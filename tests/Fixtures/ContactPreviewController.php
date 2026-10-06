<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Form\ContactFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Test-only route: exercise the actual partial without mounting it on the homepage.
final class ContactPreviewController extends AbstractController
{
    #[Route('/_test/contact', name: 'test_contact_preview', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('contact/_form.html.twig', [
            'contact_form' => $this->createForm(ContactFormType::class)->createView(),
        ]);
    }
}
