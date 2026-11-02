<?php

declare(strict_types=1);

namespace App\Tests;

use App\Message\RunPlanningJob;
use App\MessageHandler\RunPlanningJobHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Engine jobs are asynchronous (docs/decisions.md D149). Tests use the real
 * Doctrine transport (inside the test's rolled-back transaction) and run
 * "the worker" explicitly — so a test can observe a job while it is still
 * QUEUED, exactly as a manager would see it.
 */
trait PlanningJobTestHelpers
{
    /** Runs every queued job through the real handler, as `messenger:consume` would. Returns how many ran. */
    private function runQueuedPlanningJobs(): int
    {
        $container = static::getContainer();
        $transport = $this->planningJobsTransport();
        $handler = $container->get(RunPlanningJobHandler::class);

        $count = 0;
        while ([] !== $envelopes = [...$transport->get()]) {
            foreach ($envelopes as $envelope) {
                $message = $envelope->getMessage();
                \assert($message instanceof RunPlanningJob);
                $handler($message);
                $transport->ack($envelope);
                ++$count;
            }
        }
        $container->get(EntityManagerInterface::class)->clear();

        return $count;
    }

    /** How many job messages are waiting in the queue right now. */
    private function queuedPlanningJobMessageCount(): int
    {
        $transport = $this->planningJobsTransport();
        \assert($transport instanceof MessageCountAwareInterface);

        return $transport->getMessageCount();
    }

    /**
     * "Générer le planning" then the worker: returns the finished job as the API shows it.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function generateNow(KernelBrowser $client, string $planningStableId, string $token, array $body = []): array
    {
        // docs/decisions.md D222: generating again a planning whose lines are already generated replaces their
        // calendar — only on explicit confirmation ("Régénérer entièrement"). A test that generates again does so on
        // purpose: it confirms, as the manager would. The refusal without confirmation has its own tests
        // (PlanningRegenerationAndLateMemberTest); pass `replaceExistingAssignments` explicitly to test it.
        if (!\array_key_exists('replaceExistingAssignments', $body) && $this->hasCompletedGeneration($planningStableId)) {
            $body['replaceExistingAssignments'] = true;
        }
        $this->api($client, 'POST', "/api/plannings/{$planningStableId}/generations", $body, $token);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->runQueuedPlanningJobs());

        return $this->api($client, 'GET', "/api/plannings/{$planningStableId}/jobs/latest", token: $token)['job'];
    }

    /**
     * docs/decisions.md D222: $body plus the explicit confirmation of a regeneration when the planning already has a
     * generated line — what "Régénérer entièrement" sends. For the tests that generate again on purpose.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function confirmingRegeneration(string $planningStableId, array $body = []): array
    {
        return $this->hasCompletedGeneration($planningStableId) && !\array_key_exists('replaceExistingAssignments', $body)
            ? $body + ['replaceExistingAssignments' => true]
            : $body;
    }

    private function hasCompletedGeneration(string $planningStableId): bool
    {
        return (bool) static::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(<<<'SQL'
            SELECT EXISTS (
              SELECT 1 FROM planning_generations g
              JOIN planning_periods pp ON pp.id = g.planning_period_id
              JOIN planning_lines pl ON pl.id = pp.planning_line_id
              JOIN plannings p ON p.id = pl.planning_id
              WHERE p.stable_id = ? AND g.status = 'COMPLETED'
            )
            SQL, [$planningStableId]);
    }

    private function planningJobsTransport(): TransportInterface
    {
        return static::getContainer()->get('messenger.transport.planning_jobs');
    }
}
