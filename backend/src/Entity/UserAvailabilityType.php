<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * UNAVAILABLE is a hard signal (eligibility = false everywhere the user
 * has a membership, regardless of team); PREFER_DUTY is a soft signal
 * that never touches eligibility (docs/availability.md). Deliberately
 * flat — no reason/category field, see docs/availability.md "Pourquoi pas
 * de raison".
 */
enum UserAvailabilityType: string
{
    case UNAVAILABLE = 'UNAVAILABLE';
    case PREFER_DUTY = 'PREFER_DUTY';
}
