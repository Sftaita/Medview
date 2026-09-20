<?php

declare(strict_types=1);

namespace App\Service;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns whatever a user typed into a strict E.164 string ("+32470123456"),
 * or null when it is not a real, dialable number (docs/decisions.md D112).
 *
 * Built on Google's libphonenumber metadata rather than a regex: number
 * plans differ per country and change, and MedVue must not hard-code
 * Belgium. $defaultRegion only decides how a number typed WITHOUT an
 * international prefix ("0470 12 34 56") is read; anything starting with
 * "+" (or "00") is interpreted on its own, whatever the region.
 */
final class PhoneNumberNormalizer
{
    public function __construct(
        #[Autowire(env: 'DEFAULT_PHONE_REGION')]
        private readonly string $defaultRegion,
    ) {
    }

    public function toE164(string $raw): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse(trim($raw), $this->defaultRegion);
        } catch (NumberParseException) {
            return null;
        }

        if (!$util->isValidNumber($number)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }
}
