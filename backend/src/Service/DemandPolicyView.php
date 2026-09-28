<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandRules;
use App\Demand\Weekday;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineDemandPolicy;
use App\Entity\User;

/**
 * Everything the configuration screen needs to draw the "source people ×
 * weekdays" matrix without rebuilding any rule client-side
 * (docs/decisions.md D162).
 */
final readonly class DemandPolicyView
{
    /**
     * @param PlanningLineDemandPolicy|null                      $policy         null = no policy, i.e. INDEPENDENT
     * @param list<DemandSourceOption>                           $sourceOptions  the lines that may be chosen as source, with their people
     * @param list<User>                                         $triggerUsers   the people of $rules' triggers, in the same order
     * @param list<array{name: string, weekdays: list<Weekday>}> $targetBlocks   the target's weekly blocks
     * @param list<Weekday>|null                                 $targetExcluded days without any duty in the target's structure; null = no structure yet
     * @param list<DemandPolicyWarning>                          $warnings
     */
    public function __construct(
        public PlanningLine $line,
        public ?PlanningLineDemandPolicy $policy,
        public DemandRules $rules,
        public ?PlanningLine $sourceLine,
        public array $triggerUsers,
        public array $sourceOptions,
        public array $targetBlocks,
        public ?array $targetExcluded,
        public array $warnings,
    ) {
    }
}
