<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RestPolicyOptions;
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D105: RestPolicyOptions is the per-`PlanningGeneration`
 * value object carrying LEGAL_MIN_REST/TEAM_MIN_REST as options, never a
 * team-wide default and never an invented duration. Pure constructor-
 * validation coverage — no persistence involved.
 */
final class RestPolicyOptionsTest extends TestCase
{
    public function testNoneHasBothPoliciesDisabledAndNoHours(): void
    {
        $options = RestPolicyOptions::none();

        self::assertFalse($options->legalMinRestEnabled);
        self::assertNull($options->legalMinRestHours);
        self::assertFalse($options->teamMinRestEnabled);
        self::assertNull($options->teamMinRestHours);
    }

    public function testBothDisabledWithNullHoursIsValid(): void
    {
        $options = new RestPolicyOptions(false, null, false, null);

        self::assertFalse($options->legalMinRestEnabled);
        self::assertFalse($options->teamMinRestEnabled);
    }

    public function testBothEnabledWithConsistentHoursIsValid(): void
    {
        $options = new RestPolicyOptions(true, 11, true, 11);

        self::assertSame(11, $options->legalMinRestHours);
        self::assertSame(11, $options->teamMinRestHours);
    }

    public function testLegalEnabledWithoutHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('legalMinRestHours must be a strictly positive integer');

        new RestPolicyOptions(true, null, false, null);
    }

    public function testLegalEnabledWithZeroHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RestPolicyOptions(true, 0, false, null);
    }

    public function testLegalEnabledWithNegativeHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RestPolicyOptions(true, -5, false, null);
    }

    public function testLegalDisabledWithHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('legalMinRestHours must be null when its policy is disabled');

        new RestPolicyOptions(false, 11, false, null);
    }

    public function testTeamEnabledWithoutHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('teamMinRestHours must be a strictly positive integer');

        new RestPolicyOptions(false, null, true, null);
    }

    public function testTeamEnabledWithZeroHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RestPolicyOptions(false, null, true, 0);
    }

    public function testTeamDisabledWithHoursIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('teamMinRestHours must be null when its policy is disabled');

        new RestPolicyOptions(false, null, false, 11);
    }

    public function testTeamHoursBelowLegalHoursIsRejectedWhenBothEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('teamMinRestHours (8) must be >= legalMinRestHours (11)');

        new RestPolicyOptions(true, 11, true, 8);
    }

    public function testTeamHoursEqualToLegalHoursIsAccepted(): void
    {
        $options = new RestPolicyOptions(true, 11, true, 11);

        self::assertSame(11, $options->teamMinRestHours);
    }

    public function testLegalOnlyEnabledNeverConstrainsTeamHours(): void
    {
        $options = new RestPolicyOptions(true, 20, false, null);

        self::assertSame(20, $options->legalMinRestHours);
        self::assertNull($options->teamMinRestHours);
    }

    public function testTeamOnlyEnabledNeverRequiresLegalHours(): void
    {
        $options = new RestPolicyOptions(false, null, true, 11);

        self::assertNull($options->legalMinRestHours);
        self::assertSame(11, $options->teamMinRestHours);
    }
}
