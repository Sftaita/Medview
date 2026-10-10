<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What surgical_hub_link_events records (docs/surgicalhub-integration.md §4.5).
 * Never the code itself, never a secret.
 */
enum SurgicalHubLinkEventKind: string
{
    case CODE_ISSUED = 'CODE_ISSUED';
    case LINKED = 'LINKED';
    /** The same pair redeemed a new code: the existing association is confirmed, not duplicated. */
    case LINK_CONFIRMED = 'LINK_CONFIRMED';
    case REFUSED_SURGICAL_HUB_ACCOUNT_TAKEN = 'REFUSED_SURGICAL_HUB_ACCOUNT_TAKEN';
    case REFUSED_MEDVUE_ACCOUNT_TAKEN = 'REFUSED_MEDVUE_ACCOUNT_TAKEN';
    case UNLINKED_LOCAL = 'UNLINKED_LOCAL';
    case UNLINKED_REMOTE = 'UNLINKED_REMOTE';
    /** SurgicalHub answered `404 link_not_found`: suspended, nothing deleted (§9). */
    case SUSPENDED_BY_UNKNOWN_LINK = 'SUSPENDED_BY_UNKNOWN_LINK';
    /** The owner redeemed a new code for the same pair while it was suspended: reading resumes. */
    case LINK_RESUMED = 'LINK_RESUMED';
    /**
     * A planning's creator launched a generation although this person's
     * SurgicalHub leave could not be refreshed and was too old (D8).
     * `details`: planning, last successful sync, failure; the actor is the creator.
     */
    case STALE_DATA_OVERRIDDEN = 'STALE_DATA_OVERRIDDEN';
}
