<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * Why a synchronisation did not complete (docs/surgicalhub-integration.md
 * §5.2, §7.3). Stored as SurgicalHubLink::$lastSyncError and shown to the
 * owner as a sentence — never a technical message, never a response body.
 * Whatever the value, nothing local was written.
 */
enum SurgicalHubSyncError: string
{
    /** This server has no SurgicalHub URL or secret. */
    case NOT_CONFIGURED = 'not_configured';
    /** Network error, timeout, redirect. */
    case UNREACHABLE = 'unreachable';
    /** SurgicalHub refused MedVue's secret. */
    case UNAUTHORIZED = 'unauthorized';
    case RATE_LIMITED = 'rate_limited';
    /** More than SurgicalHub's cap of absences in the window. */
    case WINDOW_TOO_LARGE = 'window_too_large';
    case SERVER_ERROR = 'server_error';
    /** Any other status, or a body outside the v1 contract (incomplete, wrong window, malformed absence, …). */
    case INVALID_RESPONSE = 'invalid_response';
    /** SurgicalHub said it did not know the association (404): suspended until the owner acts (§9). */
    case LINK_SUSPENDED = 'link_suspended';
}
