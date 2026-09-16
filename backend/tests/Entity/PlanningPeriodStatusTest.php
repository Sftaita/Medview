<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PlanningPeriodStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanningPeriodStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{PlanningPeriodStatus, PlanningPeriodStatus, bool}>
     */
    public static function transitions(): iterable
    {
        yield 'draft to generated is legal' => [PlanningPeriodStatus::DRAFT, PlanningPeriodStatus::GENERATED, true];
        yield 'draft to published is illegal (skips validation)' => [PlanningPeriodStatus::DRAFT, PlanningPeriodStatus::PUBLISHED, false];
        yield 'generated to validated is legal' => [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, true];
        yield 'generated to published is illegal' => [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::PUBLISHED, false];
        yield 'validated to published is legal' => [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, true];
        yield 'validated back to generated is legal (regeneration invalidates validation)' => [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::GENERATED, true];
        yield 'published to archived is legal' => [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED, true];
        yield 'published back to draft is illegal' => [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::DRAFT, false];
        yield 'published back to validated is illegal' => [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::VALIDATED, false];
        yield 'archived is terminal' => [PlanningPeriodStatus::ARCHIVED, PlanningPeriodStatus::PUBLISHED, false];
        yield 'archived cannot re-archive' => [PlanningPeriodStatus::ARCHIVED, PlanningPeriodStatus::ARCHIVED, false];
    }

    #[DataProvider('transitions')]
    public function testCanTransitionTo(PlanningPeriodStatus $from, PlanningPeriodStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }
}
