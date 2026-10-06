<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ContactFormType;
use App\Form\ContactInquiry;
use App\Mailer\ContactMailer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'limiter.contact_form')]
        private readonly RateLimiterFactory $limiter,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ContactMailer $mailer,
        #[Autowire(service: 'monolog.logger.contact')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/contact', name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->feedback('error', 'contact.error.rate_limit');
        }

        $data = $request->request->all('contact');
        $token = $data['_token'] ?? '';
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken('contact_form', $token))) {
            return $this->feedback('error', 'contact.error.csrf');
        }

        $honeypot = $data['hp_url_a8f3c'] ?? '';
        if (!is_string($honeypot) || trim($honeypot) !== '') {
            $this->logger->info('Contact honeypot triggered.');
            return $this->feedback('success', 'contact.success');
        }

        $inquiry = new ContactInquiry();
        $form = $this->createForm(ContactFormType::class, $inquiry);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->feedback('error', 'contact.error.invalid');
        }

        try {
            $this->mailer->sendInquiry($inquiry);
        } catch (\Throwable $exception) {
            // Do not put visitor messages or SMTP credentials into logs.
            $this->logger->error('Contact delivery failed.', ['exception_type' => $exception::class]);
            return $this->feedback('error', 'contact.error.delivery');
        }

        $this->logger->info('Contact inquiry delivered.');
        return $this->feedback('success', 'contact.success');
    }

    private function feedback(string $type, string $translationKey): Response
    {
        $this->addFlash('contact_'.$type, $translationKey);
        return $this->redirectToRoute('home', ['_fragment' => 'contact'], Response::HTTP_SEE_OTHER);
    }
}
