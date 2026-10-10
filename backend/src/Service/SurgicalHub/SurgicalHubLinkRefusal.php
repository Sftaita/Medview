<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * Why a code exchange was refused — the values are the API error codes of
 * POST /api/integrations/surgicalhub/v1/link-codes/redeem
 * (docs/surgicalhub-integration.md §5.1).
 */
enum SurgicalHubLinkRefusal: string
{
    /** Unknown, expired, superseded or already consumed — deliberately indistinguishable. */
    case INVALID_CODE = 'invalid_code';
    /** This SurgicalHub account is already associated with another MedVue account. */
    case SURGICAL_HUB_ACCOUNT_TAKEN = 'already_linked';
    /** The code's MedVue account is already associated with another SurgicalHub account. */
    case MEDVUE_ACCOUNT_TAKEN = 'medvue_account_already_linked';
}
