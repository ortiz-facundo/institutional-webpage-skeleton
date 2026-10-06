<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ContactFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'contact_form' => $this->createForm(ContactFormType::class)->createView(),
        ]);
    }
}
