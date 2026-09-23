<?php

declare(strict_types=1);

namespace App\Exception;

final class NoOpenAvailabilityCollectionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This planning has no open availability collection: there is no deadline to set.');
    }
}
