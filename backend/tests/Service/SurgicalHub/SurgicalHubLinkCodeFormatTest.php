<?php

declare(strict_types=1);

namespace App\Tests\Service\SurgicalHub;

use App\Service\SurgicalHub\SurgicalHubLinkCodeFormat;
use PHPUnit\Framework\TestCase;

final class SurgicalHubLinkCodeFormatTest extends TestCase
{
    public function testGeneratedCodesAreTwelveCrockfordCharactersAndDistinct(): void
    {
        $codes = [];
        for ($i = 0; $i < 500; ++$i) {
            $code = SurgicalHubLinkCodeFormat::generate();
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{12}$/', $code);
            self::assertSame($code, SurgicalHubLinkCodeFormat::normalize($code));
            $codes[$code] = true;
        }

        self::assertCount(500, $codes);
    }

    public function testDisplayGroupsByFour(): void
    {
        self::assertSame('ABCD-EFGH-JKMN', SurgicalHubLinkCodeFormat::display('ABCDEFGHJKMN'));
    }

    public function testNormalizeForgivesWhatAPersonTypes(): void
    {
        self::assertSame('ABCDEFGHJKMN', SurgicalHubLinkCodeFormat::normalize(' abcd-efgh jkmn '));
        self::assertSame('0011ABCDEFGH', SurgicalHubLinkCodeFormat::normalize('oOiL-ABCD-EFGH'));
    }

    public function testNormalizeRejectsWhatCannotBeACode(): void
    {
        self::assertNull(SurgicalHubLinkCodeFormat::normalize(''));
        self::assertNull(SurgicalHubLinkCodeFormat::normalize('ABCD-EFGH-JKM'));
        self::assertNull(SurgicalHubLinkCodeFormat::normalize('ABCD-EFGH-JKMNP'));
        self::assertNull(SurgicalHubLinkCodeFormat::normalize('ABCD-EFGH-JKMU'));
        self::assertNull(SurgicalHubLinkCodeFormat::normalize('ABCD-EFGH-JKM!'));
        self::assertNull(SurgicalHubLinkCodeFormat::normalize('ABCDÉFGHJKMN'));
    }

    public function testHashIsTakenOnTheNormalizedForm(): void
    {
        self::assertSame(hash('sha256', 'ABCDEFGHJKMN'), SurgicalHubLinkCodeFormat::hash('ABCDEFGHJKMN'));
        self::assertSame(
            SurgicalHubLinkCodeFormat::hash((string) SurgicalHubLinkCodeFormat::normalize('abcd-efgh-jkmn')),
            SurgicalHubLinkCodeFormat::hash((string) SurgicalHubLinkCodeFormat::normalize('ABCDEFGHJKMN')),
        );
    }
}
