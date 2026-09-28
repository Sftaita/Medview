<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\CommitmentInterval;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\RestPolicyOptions;
use App\Service\DutyMaterializationService;
use App\Service\PersonCommitmentChecker;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The single incompatibility rule between a block of duties and what the
 * same person already holds (docs/decisions.md D161) — shared by the frozen
 * check (EligibilityService) and the live one (ReassignmentCandidateService).
 */
final class PersonCommitmentCheckerTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private PersonCommitmentChecker $checker;
    private DutyMaterializationService $materializer;
    private \App\Entity\PlanningPeriod $period;
    private \App\Entity\DutyType $dutyType;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->checker = self::getContainer()->get(PersonCommitmentChecker::class);
        $this->materializer = self::getContainer()->get(DutyMaterializationService::class);
        $team = $this->createTeam($em);
        $this->period = $this->createPlanningPeriod($em, $team);
        $this->dutyType = $this->createDutyType($em, $team);
    }

    private function duty(string $from, string $to): Duty
    {
        return $this->createDuty($this->materializer, $this->period, $this->dutyType, $from, $to);
    }

    /** A duty held on another line (another generation), with that generation's rest thresholds. */
    private function otherLine(string $from, string $to, ?int $legal = null, ?int $team = null): CommitmentInterval
    {
        return new CommitmentInterval($this->instant($from), $this->instant($to), false, $legal, $team, 'other-duty', 'other-line');
    }

    /** A duty of the very generation being solved or edited (same line). */
    private function sameLine(string $from, string $to): CommitmentInterval
    {
        return new CommitmentInterval($this->instant($from), $this->instant($to), true, 99, 99, 'same-duty', 'same-line');
    }

    private function instant(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Brussels'));
    }

    private function teamRest(int $hours): RestPolicyOptions
    {
        return new RestPolicyOptions(false, null, true, $hours);
    }

    public function testNoCommitmentMeansNoViolation(): void
    {
        self::assertNull($this->checker->firstViolation([$this->duty('2027-01-05 08:00', '2027-01-05 20:00')], [], RestPolicyOptions::none()));
    }

    public function testAnOverlapWithAnotherLineIsACrossLineConflictAndNamesTheCommitment(): void
    {
        $commitment = $this->otherLine('2027-01-05 18:00', '2027-01-06 08:00');

        $violation = $this->checker->firstViolation([$this->duty('2027-01-05 08:00', '2027-01-05 20:00')], [$commitment], RestPolicyOptions::none());

        self::assertSame(ExclusionReason::CROSS_LINE_CONFLICT, $violation?->reason);
        self::assertSame($commitment, $violation->commitment);
    }

    public function testAnOverlapWithinTheSameGenerationStaysAPlainConflict(): void
    {
        $violation = $this->checker->firstViolation([$this->duty('2027-01-05 08:00', '2027-01-05 20:00')], [$this->sameLine('2027-01-05 18:00', '2027-01-06 08:00')], RestPolicyOptions::none());

        self::assertSame(ExclusionReason::CONFLICT, $violation?->reason);
    }

    public function testBackToBackDutiesOnTwoLinesAreCompatibleWithoutAnyRestRule(): void
    {
        // Whole calendar days, 00:00 → 00:00: touching, never overlapping.
        self::assertNull($this->checker->firstViolation([$this->duty('2027-01-06', '2027-01-07')], [$this->otherLine('2027-01-05 00:00', '2027-01-06 00:00')], RestPolicyOptions::none()));
    }

    public function testCrossLineTeamRestBoundariesUseStrictLessThan(): void
    {
        $block = [$this->duty('2027-01-05 08:00', '2027-01-05 20:00')];

        // The block ends at 20:00; the other line's duty starts 11 h, 12 h and 13 h later.
        $below = $this->otherLine('2027-01-06 07:00', '2027-01-06 19:00');   // 11 h
        $equal = $this->otherLine('2027-01-06 08:00', '2027-01-06 20:00');   // 12 h
        $above = $this->otherLine('2027-01-06 09:00', '2027-01-06 21:00');   // 13 h

        self::assertSame(ExclusionReason::CROSS_LINE_TEAM_MIN_REST, $this->checker->firstViolation($block, [$below], $this->teamRest(12))?->reason);
        self::assertNull($this->checker->firstViolation($block, [$equal], $this->teamRest(12)), 'A gap exactly equal to the minimum is allowed.');
        self::assertNull($this->checker->firstViolation($block, [$above], $this->teamRest(12)));
    }

    public function testTheOtherGenerationsRestRuleAppliesEvenWhenTheEditedOneHasNone(): void
    {
        $block = [$this->duty('2027-01-05 08:00', '2027-01-05 20:00')];
        $tenHoursLater = $this->otherLine('2027-01-06 06:00', '2027-01-06 18:00', legal: 11);

        self::assertSame(ExclusionReason::CROSS_LINE_LEGAL_MIN_REST, $this->checker->firstViolation($block, [$tenHoursLater], RestPolicyOptions::none())?->reason);
    }

    public function testTheStricterOfBothGenerationsThresholdsIsUsed(): void
    {
        $block = [$this->duty('2027-01-05 08:00', '2027-01-05 20:00')];
        $twelveHoursLater = $this->otherLine('2027-01-06 08:00', '2027-01-06 20:00', team: 14);

        // The edited generation only asks for 10 h, the other line's generation asked for 14 h: 12 h is too short.
        self::assertSame(ExclusionReason::CROSS_LINE_TEAM_MIN_REST, $this->checker->firstViolation($block, [$twelveHoursLater], $this->teamRest(10))?->reason);
        // And the other way round: 14 h here, 10 h there.
        self::assertSame(ExclusionReason::CROSS_LINE_TEAM_MIN_REST, $this->checker->firstViolation($block, [$this->otherLine('2027-01-06 08:00', '2027-01-06 20:00', team: 10)], $this->teamRest(14))?->reason);
    }

    public function testASameLineRestRuleIsNeverTightenedByAnotherGeneration(): void
    {
        // A duty of the SAME generation is checked with that generation's own thresholds only (D131).
        $block = [$this->duty('2027-01-05 08:00', '2027-01-05 20:00')];
        $sameLine = new CommitmentInterval($this->instant('2027-01-06 08:00'), $this->instant('2027-01-06 20:00'), true, null, 99, 'same-duty', 'same-line');

        self::assertNull($this->checker->firstViolation($block, [$sameLine], $this->teamRest(12)));
    }

    public function testPrecedenceIsOverlapThenLegalThenTeamSameLineFirst(): void
    {
        $block = [$this->duty('2027-01-05 08:00', '2027-01-05 20:00')];
        $crossOverlap = $this->otherLine('2027-01-05 10:00', '2027-01-05 12:00');
        $sameOverlap = $this->sameLine('2027-01-05 12:00', '2027-01-05 14:00');
        $crossTeamRest = $this->otherLine('2027-01-06 01:00', '2027-01-06 05:00', team: 12);
        $crossLegalRest = $this->otherLine('2027-01-06 02:00', '2027-01-06 06:00', legal: 11);

        self::assertSame(ExclusionReason::CONFLICT, $this->checker->firstViolation($block, [$crossOverlap, $crossTeamRest, $sameOverlap], RestPolicyOptions::none())?->reason);
        self::assertSame(ExclusionReason::CROSS_LINE_CONFLICT, $this->checker->firstViolation($block, [$crossLegalRest, $crossOverlap], RestPolicyOptions::none())?->reason);
        self::assertSame(ExclusionReason::CROSS_LINE_LEGAL_MIN_REST, $this->checker->firstViolation($block, [$crossTeamRest, $crossLegalRest], RestPolicyOptions::none())?->reason);
    }

    public function testAnyDutyOfABlockMakesTheWholeBlockIncompatible(): void
    {
        $block = [$this->duty('2027-01-09', '2027-01-10'), $this->duty('2027-01-10', '2027-01-11')];

        $violation = $this->checker->firstViolation($block, [$this->otherLine('2027-01-10 08:00', '2027-01-10 20:00')], RestPolicyOptions::none());

        self::assertSame(ExclusionReason::CROSS_LINE_CONFLICT, $violation?->reason);
    }

    public function testRestIsMeasuredInRealHoursAcrossADstChange(): void
    {
        // Europe/Brussels springs forward on 2027-03-28 at 02:00: 00:00 → 12:00 wall clock is only 11 real hours.
        $block = [$this->duty('2027-03-27 13:00', '2027-03-28 00:00')];
        $noonNextDay = $this->otherLine('2027-03-28 11:00', '2027-03-28 20:00', team: 11);

        self::assertSame(ExclusionReason::CROSS_LINE_TEAM_MIN_REST, $this->checker->firstViolation($block, [$noonNextDay], RestPolicyOptions::none())?->reason, '10 real hours of rest, not 11.');
    }
}
