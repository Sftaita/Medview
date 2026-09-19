<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionType;
use PHPUnit\Framework\TestCase;

final class FairnessDimensionKeyTest extends TestCase
{
    public function testDutyTypeRequiresAStableId(): void
    {
        $key = FairnessDimensionKey::dutyType('some-stable-id');

        self::assertSame(FairnessDimensionType::DUTY_TYPE, $key->type);
        self::assertSame('some-stable-id', $key->dutyTypeStableId);
    }

    public function testNonDutyTypeKeysNeverCarryADutyTypeStableId(): void
    {
        self::assertNull(FairnessDimensionKey::totalDuties()->dutyTypeStableId);
        self::assertNull(FairnessDimensionKey::friday()->dutyTypeStableId);
    }

    public function testStringKeyDistinguishesDutyTypeVariantsButNotOtherwise(): void
    {
        self::assertSame('TOTAL_DUTIES', FairnessDimensionKey::totalDuties()->toStringKey());
        self::assertSame('DUTY_TYPE:abc', FairnessDimensionKey::dutyType('abc')->toStringKey());
        self::assertNotSame(
            FairnessDimensionKey::dutyType('abc')->toStringKey(),
            FairnessDimensionKey::dutyType('def')->toStringKey(),
        );
    }

    public function testEqualsComparesByValueNotByIdentity(): void
    {
        self::assertTrue(FairnessDimensionKey::friday()->equals(FairnessDimensionKey::friday()));
        self::assertTrue(FairnessDimensionKey::dutyType('abc')->equals(FairnessDimensionKey::dutyType('abc')));
        self::assertFalse(FairnessDimensionKey::dutyType('abc')->equals(FairnessDimensionKey::dutyType('def')));
        self::assertFalse(FairnessDimensionKey::friday()->equals(FairnessDimensionKey::saturday()));
    }
}
