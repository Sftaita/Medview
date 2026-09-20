<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validNumbers(): iterable
    {
        yield 'belgian mobile, national, BE region' => ['BE', '0470 12 34 56', '+32470123456'];
        yield 'belgian mobile, international' => ['BE', '+32 470 12 34 56', '+32470123456'];
        yield 'belgian mobile, 00 prefix' => ['BE', '0032470123456', '+32470123456'];
        yield 'belgian landline Brussels' => ['BE', '02 555 12 12', '+3225551212'];
        yield 'dots and dashes' => ['BE', '0470.12.34.56', '+32470123456'];
        yield 'french mobile, international, BE region' => ['BE', '+33 6 12 34 56 78', '+33612345678'];
        yield 'french national with FR region' => ['FR', '06 12 34 56 78', '+33612345678'];
        yield 'surrounding spaces' => ['BE', '  +32470123456  ', '+32470123456'];
    }

    #[DataProvider('validNumbers')]
    public function testItProducesStrictE164(string $region, string $raw, string $expected): void
    {
        self::assertSame($expected, (new PhoneNumberNormalizer($region))->toE164($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['call me'];
        yield 'too short' => ['+32 12'];
        yield 'unassigned range' => ['+32 999 99 99 99 99 99'];
        yield 'national number of another country under BE' => ['06 12 34 56 78'];
    }

    #[DataProvider('invalidNumbers')]
    public function testItRejectsWhatIsNotARealNumber(string $raw): void
    {
        self::assertNull((new PhoneNumberNormalizer('BE'))->toE164($raw));
    }

    public function testTheDefaultRegionOnlyAffectsNumbersWithoutAnInternationalPrefix(): void
    {
        $fr = new PhoneNumberNormalizer('FR');

        self::assertSame('+32470123456', $fr->toE164('+32470123456'), 'A "+" number never depends on the region.');
        self::assertSame('+33612345678', $fr->toE164('0612345678'));
    }
}
