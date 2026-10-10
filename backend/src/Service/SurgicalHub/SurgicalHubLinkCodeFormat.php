<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * The association code's shape (docs/surgicalhub-integration.md §4.2):
 * 12 characters of Crockford base32 (60 random bits), shown as
 * XXXX-XXXX-XXXX. Typed by a person, so reading it back forgives case,
 * spaces, dashes and the usual look-alikes (O→0, I/L→1) — the hash is
 * always taken on the normalized form.
 */
final class SurgicalHubLinkCodeFormat
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const LENGTH = 12;

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /** XXXX-XXXX-XXXX, for display only. */
    public static function display(string $normalized): string
    {
        return implode('-', str_split($normalized, 4));
    }

    /** The canonical form, or null when the input cannot be a code at all. */
    public static function normalize(string $input): ?string
    {
        $code = strtr(strtoupper(preg_replace('/[\s-]+/', '', $input) ?? ''), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (self::LENGTH !== \strlen($code) || \strlen($code) !== strspn($code, self::ALPHABET)) {
            return null;
        }

        return $code;
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }
}
