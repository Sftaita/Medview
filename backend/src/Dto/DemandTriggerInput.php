<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class DemandTriggerInput
{
    /**
     * @param list<string> $weekdays
     */
    public function __construct(
        public string $userStableId,
        public array $weekdays,
        public int $increment,
    ) {
    }
}
