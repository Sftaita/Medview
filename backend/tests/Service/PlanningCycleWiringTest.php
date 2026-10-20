<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\Planning;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\PlanningCycle;
use App\Entity\PlanningJob;
use App\Entity\PlanningPublication;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L1 (docs/planning-cycles.md, D186): every row this release writes for a
 * Planning is attached to its single cycle, through the real endpoints —
 * creation, lines, extension, collections, generation jobs, publication,
 * reminders — and the database then passes every blocking invariant of
 * app:planning-cycles:audit. Nothing visible changes: the responses are the
 * ones the existing controller tests already pin down.
 */
final class PlanningCycleWiringTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    public function testCreatingAPlanningCreatesItsCycleOneAndAttachesItsPrimaryPeriodAndFirstCollection(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $planning = $this->planning($s);

        $cycles = $this->cycles($planning);
        self::assertCount(1, $cycles);
        $cycle = $cycles[0];
        self::assertSame(1, $cycle->getNumber());
        self::assertTrue($cycle->hasSameDatesAs($planning->getStartsAt(), $planning->getEndsAt()));
        self::assertSame('creator@example.com', $cycle->getCreatedBy()?->getEmail());

        $line = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        self::assertSame($cycle, $line->requireSingleCyclePeriod()->getCycle());
        self::assertSame($line, $line->requireSingleCyclePeriod()->getLine());

        foreach ($this->rows(AvailabilityCollection::class, $planning) as $collection) {
            self::assertSame($cycle->getId(), $collection->getCycle()?->getId());
        }
        $this->assertAuditPasses();
    }

    public function testAnAddedLineAndAnExtensionStayInTheSingleCycle(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-07-01'], $s['creator']);
        self::assertResponseIsSuccessful();

        $planning = $this->planning($s);
        $cycles = $this->cycles($planning);
        self::assertCount(1, $cycles);
        self::assertSame('2027-07-01', $cycles[0]->getEndsAt()->format('Y-m-d'));

        $lines = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertSame($cycles[0], $line->requireSingleCyclePeriod()->getCycle());
            self::assertSame($line, $line->requireSingleCyclePeriod()->getLine());
            self::assertSame('2027-07-01', $line->requireSingleCyclePeriod()->getEndsAt()->format('Y-m-d'));
        }

        $collections = $this->rows(AvailabilityCollection::class, $planning);
        self::assertCount(2, $collections, 'the initial collection and the one opened for the new range');
        foreach ($collections as $collection) {
            self::assertSame($cycles[0]->getId(), $collection->getCycle()?->getId());
        }
        $this->assertAuditPasses();
    }

    public function testGenerationJobsPublicationsAndRemindersAreAttachedToTheCycle(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $planning = $this->planning($s);
        $cycle = $this->cycles($planning)[0];

        $jobs = $this->rows(PlanningJob::class, $planning);
        $publications = $this->rows(PlanningPublication::class, $planning);
        $reminders = $this->rows(PlanningAvailabilityReminder::class, $planning);
        self::assertNotEmpty($jobs);
        self::assertCount(1, $publications);
        self::assertNotEmpty($reminders);
        foreach ([...$jobs, ...$publications, ...$reminders] as $row) {
            self::assertSame($cycle->getId(), $row->getCycle()?->getId());
        }
        $this->assertAuditPasses();
    }

    // --- helpers -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $s
     */
    private function planning(array $s): Planning
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        self::assertNotNull($planning);
        static::getContainer()->get(EntityManagerInterface::class)->refresh($planning);

        return $planning;
    }

    /**
     * @return list<PlanningCycle>
     */
    private function cycles(Planning $planning): array
    {
        return static::getContainer()->get(PlanningCycleRepository::class)->findByPlanning($planning);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function rows(string $class, Planning $planning): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository($class)->findBy(['planning' => $em->getReference(Planning::class, $planning->getId())]);
    }

    private function assertAuditPasses(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame([], $audit->violations($audit->report()));
    }
}
