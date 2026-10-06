<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Form\ContactInquiry;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContactMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly string $senderAddress,
        private readonly string $senderName,
        private readonly string $recipientAddress,
        private readonly string $recipientName,
        private readonly string $siteName,
        private readonly string $transportDsn,
    ) {
    }

    public function sendInquiry(ContactInquiry $inquiry): void
    {
        // A disabled transport must not report successful delivery to a visitor.
        if ($this->transportDsn === '' || str_starts_with($this->transportDsn, 'null://')) {
            throw new \LogicException('Contact mail transport is not configured.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->senderAddress, $this->senderName))
            ->to(new Address($this->recipientAddress, $this->recipientName))
            ->replyTo(new Address((string) $inquiry->email, (string) $inquiry->name))
            ->subject($this->translator->trans('contact.mail.subject', ['%company%' => $this->siteName]))
            ->htmlTemplate('contact/emails/inquiry.html.twig')
            ->textTemplate('contact/emails/inquiry.txt.twig')
            ->context(['inquiry' => $inquiry, 'company_name' => $this->siteName]);
        $email->getHeaders()->addTextHeader('X-Transport', 'contact');
        $this->mailer->send($email);
    }
}
