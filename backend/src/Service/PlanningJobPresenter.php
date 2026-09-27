<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningJob;

/**
 * The API shape of a PlanningJob (docs/decisions.md D149). Never the
 * internal failure detail (exception class/message) — only the stable
 * failure code the screen turns into a sentence.
 */
final class PlanningJobPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(PlanningJob $job): array
    {
        return [
            'stableId' => (string) $job->getStableId(),
            'kind' => $job->getKind()->value,
            'status' => $job->getStatus()->value,
            'requestedBy' => ['firstName' => $job->getRequestedBy()->getFirstName(), 'lastName' => $job->getRequestedBy()->getLastName()],
            'createdAt' => $job->getCreatedAt()->format(\DATE_ATOM),
            'startedAt' => $job->getStartedAt()?->format(\DATE_ATOM),
            'finishedAt' => $job->getFinishedAt()?->format(\DATE_ATOM),
            'failureCode' => $job->getFailureCode(),
            'outcome' => $job->getOutcome(),
        ];
    }
}
