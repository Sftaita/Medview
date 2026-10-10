<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/integrations/surgicalhub/v1/link-codes/redeem payload, sent by
 * SurgicalHub's server (docs/surgicalhub-integration.md §5.1). Everything
 * here comes *from* SurgicalHub; the answer carries nothing about MedVue.
 * $code is only checked by its hash, never echoed, logged or validated by
 * shape here (a malformed code is just an invalid one).
 */
final class RedeemSurgicalHubLinkCodeRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $code = '';

    /** SurgicalHub's own identifier of the account being associated. */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]{1,64}$/')]
    public string $surgicalHubUserId = '';

    /** How SurgicalHub names that account — shown to the MedVue owner and in their email. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $surgicalHubDisplayName = '';

    /** Who typed the code in SurgicalHub (the person, or an administrator). */
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $actorDisplayName = '';

    /** True when a SurgicalHub administrator associated someone else's account. */
    public bool $actorIsAdministrator = false;
}
