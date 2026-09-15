<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;

final class SecurityController
{
    /**
     * Never actually executed: the "login" firewall's json_login
     * authenticator intercepts POST /api/login before the controller is
     * reached. This route only exists so Symfony's router has something to
     * match and generate URLs from.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('This code should never be reached.');
    }
}
