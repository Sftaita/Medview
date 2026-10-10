<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * A validated, complete answer of GET …/links/{linkId}/absences: every
 * absence of the person intersecting [$from, $to] (dates, both inclusive).
 * Only a snapshot like this one may make the synchronisation delete an
 * imported period.
 */
final readonly class SurgicalHubAbsenceSnapshot
{
    /**
     * @param array<array-key, SurgicalHubAbsence> $absences indexed by id (a numeric id becomes an int key: read SurgicalHubAbsence::$id)
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $absences,
    ) {
    }
}
