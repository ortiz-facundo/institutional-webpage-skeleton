<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service profile page. Renders the currently-authenticated user
 * (which is the impersonated user while a SwitchUserToken is active — by
 * design, see the impersonation session decision in AGENTS.md).
 *
 * Read-only for now. Edit-your-name / upload-avatar are out of scope
 * for this iteration.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProfileController extends AbstractController
{
    #[Route('/account/profile', name: 'app_account_profile', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('account/profile.html.twig');
    }
}