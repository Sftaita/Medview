<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningCycle;
use App\Exception\PlanningCycleException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\FairnessPeriodRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningJobRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\AvailabilityCollectionService;
use App\Service\FairnessPeriodService;
use App\Service\PeriodBoundaryGuard;
use App\Service\PeriodDateRules;
use App\Service\PlanningCycleService;
use App\Service\PlanningPeriodLifecycleService;
use App\Service\PlanningPeriodRemover;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The activation switch of the periods (docs/planning-cycles.md §6.1,
 * docs/decisions.md D187): MEDVUE_PERIOD_CREATION_ENABLED is 0 in the
 * shipped .env, and while it is off nothing can create, move or delete a
 * period, nor open a collection through the period endpoint — the test
 * environment turns it on for the other tests.
 */
final class PlanningCycleServiceTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    public function testTheShippedDefaultIsDisabled(): void
    {
        $values = (new Dotenv())->parse((string) file_get_contents(\dirname(__DIR__, 2).'/.env'));

        self::assertSame('0', $values['MEDVUE_PERIOD_CREATION_ENABLED'] ?? null);
    }

    public function testWhileDisabledNoPeriodOperationGoesThrough(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $container = static::getContainer();
        $service = new PlanningCycleService(
            $container->get(EntityManagerInterface::class),
            $container->get(PlanningCycleRepository::class),
            $container->get(PlanningLineRepository::class),
            $container->get(FairnessPeriodRepository::class),
            $container->get(FairnessPeriodService::class),
            $container->get(PlanningPeriodLifecycleService::class),
            $container->get(PlanningPeriodRemover::class),
            $container->get(PlanningJobRepository::class),
            $container->get(AvailabilityCollectionRepository::class),
            $container->get(AvailabilityCollectionService::class),
            $container->get(PeriodBoundaryGuard::class),
            $container->get(PeriodDateRules::class),
            new NullLogger(),
            false,
        );
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        self::assertNotNull($planning);
        $creator = $container->get(UserRepository::class)->findOneByEmail('creator@example.com');
        self::assertNotNull($creator);
        $cycle = $planning->getOnlyCycle();
        self::assertFalse($service->isCreationEnabled());

        $operations = [
            'period_creation_disabled' => fn () => $service->createNext($planning, (string) $cycle->getStableId(), new \DateTimeImmutable('2027-08-01'), $creator),
            'period_management_disabled' => fn () => $service->changeEnd($planning, $cycle, new \DateTimeImmutable('2027-06-01'), new \DateTimeImmutable('2027-05-01')),
        ];
        $operations[] = fn () => $service->changeDates($planning, $cycle, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2027-01-01'), null, null);
        $operations[] = fn () => $service->deleteLast($planning, $cycle, (string) $cycle->getStableId());
        $operations[] = fn () => $service->openCollection($planning, $cycle, $creator, null, null, null);

        foreach ($operations as $code => $operation) {
            try {
                $operation();
                self::fail('expected a refusal while disabled');
            } catch (PlanningCycleException $exception) {
                self::assertSame(409, $exception->statusCode());
                self::assertSame(\is_string($code) ? $code : 'period_management_disabled', $exception->errorCode());
            }
        }

        self::assertCount(1, $container->get(PlanningCycleRepository::class)->findByPlanning($planning));
        self::assertSame('2027-05-01', $container->get(PlanningCycleRepository::class)->findByPlanning($planning)[0]->getEndsAt()->format('Y-m-d'));
        self::assertInstanceOf(PlanningCycle::class, $cycle);
    }
}
