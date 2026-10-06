<?php

declare(strict_types=1);

namespace App\Tests;

use App\Form\ContactInquiry;
use App\Mailer\ContactMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ContactTest extends WebTestCase
{
    public function testHomepageIsBlankAndHasNoBackofficeOrPreviewRoutes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form');
        self::assertSelectorTextContains('title', self::getContainer()->getParameter('site.name'));
        self::assertSame('', trim($client->getCrawler()->filter('body')->text()));
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/contact');
        self::assertResponseStatusCodeSame(405);
    }

    public function testValidInquiryUsesDedicatedTransportReplyToAndEscapedTemplates(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/_test/contact');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $this->resetLimiter();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function (RawMessage $message): bool {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            self::assertSame('visitor@example.test', $message->getReplyTo()[0]->getAddress());
            self::assertSame($_ENV['CONTACT_MAIL_FROM'], $message->getFrom()[0]->getAddress());
            self::assertSame($_ENV['CONTACT_MAIL_TO'], $message->getTo()[0]->getAddress());
            self::assertSame('contact', $message->getHeaders()->get('X-Transport')->getBodyAsString());
            $twig = self::getContainer()->get(Environment::class);
            $html = $twig->render($message->getHtmlTemplate(), $message->getContext());
            $text = $twig->render($message->getTextTemplate(), $message->getContext());
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('<script>', $text);
            return true;
        }));
        self::getContainer()->set(MailerInterface::class, $mailer);
        $client->request('POST', '/contact', ['contact' => $this->validData($token)], [], $this->origin());
        self::assertResponseRedirects('/#contact', 303);
        $crawler = $client->request('GET', '/_test/contact');
        self::assertSame(1, $crawler->filter('[role="status"]')->count());
    }

    #[DataProvider('rejectedSubmissions')]
    public function testRejectedSubmissionsDoNotSendMail(array $changes, bool $sameOrigin, string $flashType): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/_test/contact');
        $token = $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $this->resetLimiter();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $client->request('POST', '/contact', ['contact' => array_replace($this->validData($token), $changes)], [],
            $sameOrigin ? $this->origin() : ['HTTP_ORIGIN' => 'https://foreign.example', 'HTTP_REFERER' => 'https://foreign.example/']);
        self::assertResponseRedirects('/#contact', 303);
        $crawler = $client->request('GET', '/_test/contact');
        self::assertSame(1, $crawler->filter($flashType === 'success' ? '[role="status"]' : '[role="alert"]')->count());
    }

    public static function rejectedSubmissions(): iterable
    {
        yield 'invalid email' => [['email' => 'invalid'], true, 'error'];
        yield 'empty name' => [['name' => ''], true, 'error'];
        yield 'oversized message' => [['message' => str_repeat('x', 5001)], true, 'error'];
        yield 'array instead of email' => [['email' => ['unexpected']], true, 'error'];
        yield 'missing CSRF' => [['_token' => ''], true, 'error'];
        yield 'foreign origin' => [[], false, 'error'];
        yield 'honeypot' => [['hp_url_a8f3c' => 'https://spam.example'], true, 'success'];
    }

    public function testRateLimitBlocksSixthSubmission(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/_test/contact');
        $token = $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $this->resetLimiter();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $client->request('POST', '/contact', ['contact' => array_replace($this->validData($token), ['hp_url_a8f3c' => 'bot'])], [], $this->origin());
            self::assertResponseRedirects('/#contact', 303);
        }
        $crawler = $client->request('GET', '/_test/contact');
        self::assertSame(5, $crawler->filter('[role="status"]')->count());
        self::assertSame(1, $crawler->filter('[role="alert"]')->count());
        self::assertStringContainsString(self::getContainer()->get(TranslatorInterface::class)->trans('contact.error.rate_limit'), $crawler->filter('[role="alert"]')->text());
    }

    public function testTransportFailureShowsErrorRatherThanSuccess(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/_test/contact');
        $token = $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $this->resetLimiter();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('SMTP unavailable'));
        self::getContainer()->set(MailerInterface::class, $mailer);
        $client->request('POST', '/contact', ['contact' => $this->validData($token)], [], $this->origin());
        $crawler = $client->request('GET', '/_test/contact');
        self::assertSame(0, $crawler->filter('[role="status"]')->count());
        self::assertStringContainsString(self::getContainer()->get(TranslatorInterface::class)->trans('contact.error.delivery'), $crawler->filter('[role="alert"]')->text());
    }

    public function testDisabledTransportCannotClaimDelivery(): void
    {
        self::bootKernel();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $contact = new ContactMailer($mailer, self::getContainer()->get(TranslatorInterface::class),
            'sender@example.test', 'Sender', 'recipient@example.test', 'Recipient', 'Company', 'null://null');
        $this->expectException(\LogicException::class);
        $contact->sendInquiry(new ContactInquiry());
    }

    private function resetLimiter(): void
    {
        self::getContainer()->get('limiter.contact_form')->create('127.0.0.1')->reset();
    }

    private function validData(string $token): array
    {
        return ['name' => 'Visitor', 'email' => 'visitor@example.test', 'phone' => '+54 11 1234 5678',
            'message' => 'Please contact me. <script>alert(1)</script>', 'hp_url_a8f3c' => '', '_token' => $token];
    }

    private function origin(): array
    {
        return ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_REFERER' => 'http://localhost/'];
    }
}
