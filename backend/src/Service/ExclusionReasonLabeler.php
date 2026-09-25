<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ExclusionReason;

/**
 * Plain-language translation of an already-computed, real exclusion code
 * (docs/eligibility.md) — never a new causality, only wording. Extracted
 * out of PlanningResultService (docs/decisions.md D131) so the live
 * reassignment candidates view (ReassignmentCandidateService) uses the
 * exact same labels, never a second, independently-maintained translation
 * that could silently drift from this one.
 */
final class ExclusionReasonLabeler
{
    public function label(ExclusionReason $reason): string
    {
        return match ($reason) {
            ExclusionReason::UNAVAILABLE => 'indisponible',
            ExclusionReason::NON_PARTICIPATION => 'non-participation déclarée sur cette période',
            ExclusionReason::CONFLICT => 'déjà affecté à une garde incompatible',
            ExclusionReason::LEGAL_MIN_REST => 'repos légal insuffisant',
            ExclusionReason::TEAM_MIN_REST => "repos d'équipe insuffisant",
            ExclusionReason::MAX_DUTIES => 'quota de gardes atteint',
            ExclusionReason::MAX_WEEKENDS => 'quota de week-ends atteint',
            ExclusionReason::MAX_CONSECUTIVE_NIGHTS => 'trop de nuits consécutives',
            ExclusionReason::USER_INACTIVE => 'compte désactivé',
            ExclusionReason::NOT_TEAM_MEMBER => "ne fait plus partie de l'équipe sur cette période",
            ExclusionReason::MEMBERSHIP_OUT_OF_RANGE => 'adhésion en dehors de cette période',
            ExclusionReason::SITE_NOT_ALLOWED => 'site non autorisé',
            ExclusionReason::MISSING_SKILL => 'compétence manquante',
            ExclusionReason::LOCK_CONFLICT => 'garde verrouillée en conflit',
            ExclusionReason::GROUP_UNAVAILABLE => 'indisponible sur une des gardes du groupe',
            ExclusionReason::RULE_EXCLUSION => "exclu par une règle de l'équipe",
        };
    }
}
