<?php

declare(strict_types=1);

namespace App\Exception;

final class EmailAlreadyUsedException extends \DomainException
{
    public function __construct(string $email)
    {
        parent::__construct(sprintf('The email "%s" is already used.', $email));
    }
}
