<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * The skeleton ships as a backoffice-only application: there is no
     * public homepage. The root URL redirects to the login page so that
     * unauthenticated visitors land somewhere useful while making the
     * "no public site" intent explicit.
     *
     * Downstream projects that want a public homepage should override
     * this controller (or its route) instead of editing this one — that
     * way the skeleton stays clean for future rebase flows.
     */
    #[Route('/', name: 'home')]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('security_login');
    }
}
