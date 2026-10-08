<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * "Chrome · Windows" instead of a raw User-Agent header in the session
 * history: enough to recognise a device, never the full string (which is
 * a fingerprinting aid nobody needs here). Deliberately coarse — an
 * unrecognised browser or system is simply "Navigateur inconnu".
 */
final class UserAgentSummary
{
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent): string
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return 'Appareil inconnu';
        }

        $browser = 'Navigateur inconnu';
        foreach (self::BROWSERS as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                $browser = $label;
                break;
            }
        }

        foreach (self::SYSTEMS as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $browser.' · '.$label;
            }
        }

        return $browser;
    }
}
