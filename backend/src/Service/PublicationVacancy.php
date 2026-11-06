<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Why a required post is still empty (docs/decisions.md D232), read with the
 * very check behind the candidate list and the save
 * (ReassignmentCandidateService::assignabilityError) — so what the dialog
 * says can never disagree with what the calendar offers.
 */
final readonly class PublicationVacancy
{
    /** Required since a reassignment: the completion creates the duty. */
    public const NOT_CREATED = 'NOT_CREATED';
    /** No member of the line belongs to it on these dates: neither the completion nor a manual assignment can fill it. */
    public const NO_MEMBER_ON_DATE = 'NO_MEMBER_ON_DATE';
    /** Members on these dates, none of them can take it (unavailable, in conflict…). */
    public const NO_ELIGIBLE_MEMBER = 'NO_ELIGIBLE_MEMBER';
    /** At least one member can take it. */
    public const ASSIGNABLE_MEMBERS = 'ASSIGNABLE_MEMBERS';

    /**
     * @param list<array{code: string, label: string, count: int}> $reasons why the members on these dates cannot take it
     */
    public function __construct(
        public string $code,
        public int $memberCount = 0,
        public int $assignableCount = 0,
        /** NO_MEMBER_ON_DATE: the first day a member joins the line after these dates, null when nobody does. */
        public ?\DateTimeImmutable $firstMembershipStart = null,
        public array $reasons = [],
    ) {
    }

    /**
     * docs/decisions.md D234: nobody can fill this post — neither a completion
     * nor a manual assignment. A member left out only because they joined after
     * the generation (NOT_IN_GENERATION_SNAPSHOT) does not count as an
     * impossibility: the completion takes late members into account
     * (PlanningCompletionService). A post not created yet is never said
     * impossible: the completion creates it.
     */
    public function isUnfillable(): bool
    {
        if (self::NO_MEMBER_ON_DATE === $this->code) {
            return true;
        }
        if (self::NO_ELIGIBLE_MEMBER !== $this->code) {
            return false;
        }
        foreach ($this->reasons as $reason) {
            if (ReassignmentCandidateService::NOT_IN_GENERATION_SNAPSHOT === $reason['code']) {
                return false;
            }
        }

        return true;
    }
}
