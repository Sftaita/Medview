<?php

declare(strict_types=1);

namespace App\Exception;

final class AvailabilityCollectionClosedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This availability collection is closed: it no longer accepts answers.');
    }
}
