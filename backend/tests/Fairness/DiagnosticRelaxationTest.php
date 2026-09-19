<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Eligibility\ConstraintTier;
use App\Eligibility\ExclusionReason;
use App\Fairness\DiagnosticRelaxation;
use PHPUnit\Framework\TestCase;

final class DiagnosticRelaxationTest extends TestCase
{
    public function testAPolicyHardRuleCanBeProposed(): void
    {
        $relaxation = new DiagnosticRelaxation(ExclusionReason::MAX_DUTIES, 'La suppression de MAX_DUTIES permettrait de retrouver une couverture complète.');

        self::assertSame(ConstraintTier::POLICY_HARD, $relaxation->tier);
    }

    public function testAHardRuleCanNeverBeProposedAsARelaxation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DiagnosticRelaxation(ExclusionReason::UNAVAILABLE, 'jamais construit');
    }

    /**
     * docs/decisions.md D105: LEGAL_MIN_REST is HARD once its per-generation
     * option is enabled — it must never be proposable as a relaxation, same
     * as any other HARD rule.
     */
    public function testLegalMinRestCanNeverBeProposedAsARelaxation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DiagnosticRelaxation(ExclusionReason::LEGAL_MIN_REST, 'jamais construit');
    }

    public function testConflictCanNeverBeProposedAsARelaxation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DiagnosticRelaxation(ExclusionReason::CONFLICT, 'jamais construit');
    }

    public function testDisclaimerDefaultsToTheConditionalWording(): void
    {
        $relaxation = new DiagnosticRelaxation(ExclusionReason::MAX_WEEKENDS, 'La suppression de MAX_WEEKENDS permettrait de retrouver une couverture complète.');

        self::assertStringContainsString('pas nécessairement la cause unique', $relaxation->disclaimer);
    }
}
