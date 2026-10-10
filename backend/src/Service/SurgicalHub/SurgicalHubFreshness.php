<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * Where one associated participant's SurgicalHub leave stands at launch
 * time (docs/surgicalhub-integration.md §7.5, decision D8).
 */
enum SurgicalHubFreshness: string
{
    /** The synchronisation just attempted succeeded. */
    case FRESH = 'FRESH';
    /** It failed, but the last success is recent enough: a warning, the launch goes on. */
    case STALE_RECENT = 'STALE_RECENT';
    /** It failed and the last success is too old, or there never was one: blocking. */
    case STALE_BLOCKING = 'STALE_BLOCKING';
}
