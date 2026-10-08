<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Shared shaping of administration responses: every instant leaves the API
 * as ISO 8601 with its UTC offset, every calendar day as "Y-m-d" of the
 * platform zone — the frontend never guesses a time zone.
 */
final class AdminFormat
{
    public static function iso(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /** A raw "Y-m-d H:i:s" TIMESTAMP column value (UTC) as ISO 8601. */
    public static function utcToIso(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
