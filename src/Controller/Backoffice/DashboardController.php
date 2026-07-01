<?php

declare(strict_types=1);

namespace App\Controller\Backoffice;

use App\Security\Permission as Perm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard', name: 'backoffice_dashboard', methods: ['GET'])]
final class DashboardController extends AbstractController
{
    #[IsGranted(Perm::PERMISSION_VIEW_BACKOFFICE)]
    public function __invoke(): Response
    {
        return $this->render('backoffice/dashboard/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}
