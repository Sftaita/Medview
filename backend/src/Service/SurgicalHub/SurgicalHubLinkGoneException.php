<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * SurgicalHub answered, in the exact v1 shape, that it no longer knows this
 * association (`404 link_not_found` or `410 link_revoked`) — the only
 * answer that may revoke it locally (docs/surgicalhub-integration.md §5.2).
 */
final class SurgicalHubLinkGoneException extends \RuntimeException
{
    /** `404`: SurgicalHub says it never knew it — a doubt (suspension), never a revocation. */
    public const NOT_FOUND = 'link_not_found';
    /** `410`: SurgicalHub revoked it. */
    public const REVOKED = 'link_revoked';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('SurgicalHub no longer knows this association: %s.', $reason));
    }
}
