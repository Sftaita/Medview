<?php

declare(strict_types=1);

namespace App\Service;

/**
 * docs/decisions.md D234: among the holes « Compléter la période » would
 * work on for one line, those nobody can fill (PublicationVacancy::isUnfillable)
 * — counted apart, so the main action is never offered for them alone and
 * the manager is told what to check instead.
 */
final readonly class FillVacancies
{
    public function __construct(
        /** Required duties without holder that no member can take (a block counts each of its days). */
        public int $unfillableDutyCount = 0,
        /** Of which: no member of the line on these dates (NO_MEMBER_ON_DATE). */
        public int $noMemberOnDateCount = 0,
        /** The first day a member joins the line after such dates, null when none or unknown. */
        public ?\DateTimeImmutable $firstMembershipStart = null,
        /** First and last date of the unfillable duties. */
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dutyCount' => $this->unfillableDutyCount,
            'noMemberOnDateCount' => $this->noMemberOnDateCount,
            'noEligibleMemberCount' => $this->unfillableDutyCount - $this->noMemberOnDateCount,
            'firstMembershipStart' => $this->firstMembershipStart?->format('Y-m-d'),
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ];
    }
}
