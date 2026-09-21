<?php

declare(strict_types=1);

namespace App\Exception;

final class AvailabilityCollectionOverlapException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This window overlaps an existing availability collection of the same planning.');
    }
}
