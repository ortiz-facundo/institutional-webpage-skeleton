<?php

declare(strict_types=1);

namespace App\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogoutController extends AbstractController
{
    /**
     * The firewall intercepts /logout before this action is reached.
     * If it is reached, security is misconfigured and the action
     * throws so the failure is loud.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): Response
    {
        throw new \LogicException('This code is reached only if logout is misconfigured; the firewall intercepts /logout.');
    }
}
