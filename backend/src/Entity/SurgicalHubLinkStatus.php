<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle of a SurgicalHub association (docs/surgicalhub-integration.md §4, §9).
 * Only ACTIVE allows reading leave. SUSPENDED is the doubtful case (SurgicalHub
 * says it never knew the association — possibly a restored backup): nothing is
 * read, nothing is deleted, until the owner associates again or dissociates.
 * The two revoked states say which side ended it, and are terminal — a new
 * association is a new row.
 */
enum SurgicalHubLinkStatus: string
{
    case ACTIVE = 'ACTIVE';
    /** SurgicalHub answered `404 link_not_found`: waiting for the owner, imports kept as they are. */
    case SUSPENDED = 'SUSPENDED';
    /** Dissociated by its MedVue owner. */
    case REVOKED_LOCAL = 'REVOKED_LOCAL';
    /** SurgicalHub revoked it (`410 link_revoked`). */
    case REVOKED_REMOTE = 'REVOKED_REMOTE';
}
