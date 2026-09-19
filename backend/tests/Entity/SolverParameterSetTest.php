<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SolverParameterSet;
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D106: a versioned, immutable, real set of solver
 * execution parameters — never a hidden constant. Pure constructor
 * validation, no persistence involved.
 */
final class SolverParameterSetTest extends TestCase
{
    public function testValidValuesAreAccepted(): void
    {
        $set = new SolverParameterSet(1, 60, 1);

        self::assertSame(1, $set->getVersion());
        self::assertSame(60, $set->getTimeoutSeconds());
        self::assertSame(1, $set->getNumWorkers());
    }

    public function testVersionMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SolverParameterSet(0, 60, 1);
    }

    public function testTimeoutMustBeStrictlyPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SolverParameterSet(1, 0, 1);
    }

    public function testNegativeTimeoutIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SolverParameterSet(1, -5, 1);
    }

    public function testNumWorkersMustBeStrictlyPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SolverParameterSet(1, 60, 0);
    }

    public function testEachInstanceGetsItsOwnStableId(): void
    {
        $a = new SolverParameterSet(1, 60, 1);
        $b = new SolverParameterSet(2, 60, 1);

        self::assertFalse($a->getStableId()->equals($b->getStableId()));
    }
}
