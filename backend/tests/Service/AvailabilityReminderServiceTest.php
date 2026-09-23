<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Planning;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningAvailabilityReminderRepository;
use App\Repository\PlanningLineRepository;
use App\Service\AvailabilityReminderMailer;
use App\Service\AvailabilityReminderService;
use App\Service\PlanningService;
use App\Service\PlanningTeamMembershipService;
use App\Service\ReminderStatus;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The reminder service against the real database with a mail transport we
 * control: what is (not) recorded when the transport refuses a message.
 */
final class AvailabilityReminderServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private EntityManagerInterface $em;
    private PlanningAvailabilityReminderRepository $reminderRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-12-10 09:00:00 UTC');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->reminderRepository = self::getContainer()->get(PlanningAvailabilityReminderRepository::class);
    }

    /**
     * @return array{0: Planning, 1: User, 2: User} planning, its creator, one pending member
     */
    private function planningWithOnePendingMember(): array
    {
        $container = self::getContainer();
        $creator = $this->createUser($this->em);
        $member = $this->createUser($this->em);
        $planning = $container->get(PlanningService::class)->create('Gardes', $creator, $this->date('2027-01-01'), $this->date('2027-05-01'), 'Europe/Brussels', 'Seniors');
        $team = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
        $this->addMember($container->get(PlanningTeamMembershipService::class), $team, $member, TeamMemberRole::MEMBER);

        return [$planning, $creator, $member];
    }

    private function service(MailerInterface $mailer): AvailabilityReminderService
    {
        $container = self::getContainer();

        return new AvailabilityReminderService(
            $container->get(AvailabilityCollectionRepository::class),
            $container->get(AvailabilityCollectionResponseRepository::class),
            $this->reminderRepository,
            new AvailabilityReminderMailer($mailer, new NullLogger(), 'http://localhost:5183', 'MedVue <no-reply@medvue.be>', 'support@medvue.be'),
            $this->em,
            $container->get(ClockInterface::class),
        );
    }

    public function testAFailedSendIsNotAudited(): void
    {
        [$planning, $creator, $member] = $this->planningWithOnePendingMember();
        $failing = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        };

        $outcome = $this->service($failing)->remind($planning, $member, $creator);

        self::assertSame(ReminderStatus::EMAIL_FAILED, $outcome->status);
        self::assertNull($outcome->reminder);
        self::assertNull($this->reminderRepository->findLastForRecipient($planning, $member), '"Last reminder" must never record a send that failed.');
    }

    public function testAFailedSendDoesNotThrottleTheRetry(): void
    {
        [$planning, $creator, $member] = $this->planningWithOnePendingMember();
        $failing = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        };
        $working = new class implements MailerInterface {
            public int $sent = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->sent;
            }
        };

        self::assertSame(ReminderStatus::EMAIL_FAILED, $this->service($failing)->remind($planning, $member, $creator)->status);
        // Nothing was recorded, so an immediate retry is not "too recent".
        self::assertSame(ReminderStatus::SENT, $this->service($working)->remind($planning, $member, $creator)->status);
        self::assertSame(1, $working->sent);
    }

    public function testTheThrottleWindowEndsAfterFiveMinutes(): void
    {
        [$planning, $creator, $member] = $this->planningWithOnePendingMember();
        $working = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
            }
        };
        $service = $this->service($working);

        self::assertSame(ReminderStatus::SENT, $service->remind($planning, $member, $creator)->status);
        self::mockTime('2026-12-10 09:04:59 UTC');
        self::assertSame(ReminderStatus::TOO_RECENT, $service->remind($planning, $member, $creator)->status);
        self::mockTime('2026-12-10 09:05:01 UTC');
        self::assertSame(ReminderStatus::SENT, $service->remind($planning, $member, $creator)->status);
    }
}
