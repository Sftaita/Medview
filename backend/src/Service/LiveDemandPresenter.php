<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandReason;
use App\Demand\DutyDemand;
use App\Demand\LiveCoverageState;
use App\Demand\UnitDemand;
use App\Entity\Duty;
use App\Entity\PlanningTeamMember;
use App\Repository\UserRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The API shape of the live demand of conditional duties (docs/decisions.md
 * D165) — one place, shared by the calendar result, the candidate list and
 * the dependentImpacts of a reassignment, so the frontend reads the same
 * explanation everywhere and never recomputes it.
 */
final class LiveDemandPresenter implements ResetInterface
{
    /** @var array<string, array{userStableId: string, firstName: string, lastName: string}|null> */
    private array $people = [];

    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function reset(): void
    {
        $this->people = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function unitToArray(UnitDemand $demand, LiveCoverageState $state): array
    {
        return [
            'state' => $state->value,
            'required' => $demand->determined ? $demand->required : null,
            'reason' => self::unitReason($demand)->value,
            'superfluous' => $state->isSuperfluous(),
            'triggeringDutyStableIds' => array_map(static fn (Duty $d): string => (string) $d->getStableId(), $demand->triggeringDuties),
            'triggeringDates' => array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $demand->triggeringDuties),
        ];
    }

    /**
     * The unit's answer plus the explanation of the duty's own day: its
     * source duty, who holds it, which trigger matched.
     *
     * @return array<string, mixed>
     */
    public function dutyToArray(UnitDemand $unit, DutyDemand $demand, LiveCoverageState $state): array
    {
        $day = $demand->ownDay ?? throw new \InvalidArgumentException('Only a conditional duty has a live demand explanation.');

        return [
            ...$this->unitToArray($unit, $state),
            'dayReason' => $day->reason->value,
            'weekday' => $day->weekday->value,
            'sourceDutyStableId' => (string) $day->sourceDuty->getStableId(),
            'sourceDate' => $day->sourceDuty->getLocalDate()->format('Y-m-d'),
            'sourceHolder' => null !== $day->sourceHolderUserStableId ? $this->person($day->sourceHolderUserStableId) : null,
            'trigger' => null === $day->trigger ? null : [
                'triggerStableId' => $day->trigger->triggerStableId,
                'weekdays' => array_map(static fn ($w): string => $w->value, $day->trigger->weekdays),
                'increment' => $day->trigger->increment,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function impactToArray(DependentImpact $impact): array
    {
        $first = $impact->block[0];
        $group = $first->getGroupInstance();

        return [
            'lineStableId' => (string) $impact->line->getStableId(),
            'lineName' => $impact->line->getName(),
            'unitStableKey' => (string) ($group?->getStableId() ?? $first->getStableId()),
            'groupInstanceStableId' => null !== $group ? (string) $group->getStableId() : null,
            'dutyStableIds' => array_map(static fn (Duty $d): string => (string) $d->getStableId(), $impact->block),
            'dates' => array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $impact->block),
            'previousState' => $impact->previousState->value,
            'newState' => $impact->newState->value,
            'changed' => $impact->changed(),
            'required' => $impact->demand->determined ? $impact->demand->required : null,
            'assigned' => null !== $impact->assignee,
            'assignee' => null !== $impact->assignee ? self::member($impact->assignee) : null,
            'reason' => self::unitReason($impact->demand)->value,
            'triggeringDates' => array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $impact->demand->triggeringDuties),
        ];
    }

    /**
     * @return array{teamMemberStableId: string, userStableId: string, firstName: string, lastName: string}
     */
    public static function member(PlanningTeamMember $member): array
    {
        return [
            'teamMemberStableId' => (string) $member->getStableId(),
            'userStableId' => (string) $member->getUser()->getStableId(),
            'firstName' => $member->getUser()->getFirstName(),
            'lastName' => $member->getUser()->getLastName(),
        ];
    }

    /**
     * One reason for a whole unit: TRIGGERED when required; for an
     * undetermined unit, the first day that could not be evaluated; for a
     * unit not required, its first day's reason.
     */
    public static function unitReason(UnitDemand $demand): DemandReason
    {
        if ($demand->required) {
            return DemandReason::TRIGGERED;
        }
        foreach ($demand->duties as $duty) {
            if (!$demand->determined && $duty->reason->isUndetermined()) {
                return $duty->reason;
            }
        }

        return $demand->duties[0]->reason;
    }

    /**
     * @return array{userStableId: string, firstName: string, lastName: string}|null
     */
    private function person(string $userStableId): ?array
    {
        if (!\array_key_exists($userStableId, $this->people)) {
            $user = $this->userRepository->findOneByStableId($userStableId);
            $this->people[$userStableId] = null === $user ? null : [
                'userStableId' => $userStableId,
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ];
        }

        return $this->people[$userStableId];
    }
}
