<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PublicationVacancy;
use App\Service\ReassignmentCandidateService;
use PHPUnit\Framework\TestCase;

/** docs/decisions.md D234 — which vacancies nobody can fill. */
final class PublicationVacancyTest extends TestCase
{
    public function testNoMemberOnTheDatesIsUnfillable(): void
    {
        self::assertTrue((new PublicationVacancy(PublicationVacancy::NO_MEMBER_ON_DATE, 6, 0, new \DateTimeImmutable('2026-11-01')))->isUnfillable());
    }

    public function testMembersAllPreventedAreUnfillable(): void
    {
        $vacancy = new PublicationVacancy(PublicationVacancy::NO_ELIGIBLE_MEMBER, 3, 0, null, [
            ['code' => 'UNAVAILABLE', 'label' => 'indisponible', 'count' => 2],
            ['code' => 'LEGAL_MIN_REST', 'label' => 'repos légal', 'count' => 1],
        ]);

        self::assertTrue($vacancy->isUnfillable());
    }

    public function testAMemberWhoJoinedAfterTheGenerationKeepsThePostFillable(): void
    {
        // The completion takes late members into account (PlanningCompletionService): not an impossibility.
        $vacancy = new PublicationVacancy(PublicationVacancy::NO_ELIGIBLE_MEMBER, 3, 0, null, [
            ['code' => 'UNAVAILABLE', 'label' => 'indisponible', 'count' => 2],
            ['code' => ReassignmentCandidateService::NOT_IN_GENERATION_SNAPSHOT, 'label' => 'ne fait pas partie de cette génération', 'count' => 1],
        ]);

        self::assertFalse($vacancy->isUnfillable());
    }

    public function testAssignableOrNotCreatedPostsAreNeverUnfillable(): void
    {
        self::assertFalse((new PublicationVacancy(PublicationVacancy::ASSIGNABLE_MEMBERS, 3, 1))->isUnfillable());
        self::assertFalse((new PublicationVacancy(PublicationVacancy::NOT_CREATED))->isUnfillable());
    }
}
