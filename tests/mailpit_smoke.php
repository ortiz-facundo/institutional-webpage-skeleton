<?php

declare(strict_types=1);

// Run inside the PHP container. Uses test-only form preview; homepage stays blank.
require __DIR__.'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel);
$crawler = $client->request('GET', '/_test/contact');
if ($client->getResponse()->getStatusCode() !== 200) {
    throw new RuntimeException('Test form preview did not render.');
}
$token = $crawler->filter('input[name="contact[_token]"]')->attr('value');
$kernel->getContainer()->get('test.service_container')->get('limiter.contact_form')->create('127.0.0.1')->reset();
$marker = 'institutional-smoke-'.bin2hex(random_bytes(8));
$client->request('POST', '/contact', ['contact' => [
    'name' => 'SMTP smoke test',
    'email' => 'visitor@example.test',
    'phone' => '',
    'message' => $marker.' <script>escaped</script>',
    'hp_url_a8f3c' => '',
    '_token' => $token,
]], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_REFERER' => 'http://localhost/']);
if ($client->getResponse()->getStatusCode() !== 303) {
    throw new RuntimeException('Contact submission did not redirect.');
}
$crawler = $client->request('GET', '/_test/contact');
if ($crawler->filter('[role="status"]')->count() !== 1 || $crawler->filter('[role="alert"]')->count()) {
    throw new RuntimeException('Contact submission reported failure.');
}
$list = json_decode(file_get_contents('http://mailer:8025/api/v1/messages'), true, flags: JSON_THROW_ON_ERROR);
foreach ($list['messages'] as $summary) {
    $message = json_decode(file_get_contents('http://mailer:8025/api/v1/message/'.$summary['ID']), true, flags: JSON_THROW_ON_ERROR);
    if (!str_contains($message['Text'], $marker)) {
        continue;
    }
    if ($message['From']['Address'] !== $_ENV['CONTACT_MAIL_FROM']
        || $message['To'][0]['Address'] !== $_ENV['CONTACT_MAIL_TO']
        || $message['ReplyTo'][0]['Address'] !== 'visitor@example.test'
        || !str_contains($message['HTML'], '&lt;script&gt;')
        || str_contains($message['HTML'], '<script>')) {
        throw new RuntimeException('Delivered email headers or escaping are incorrect.');
    }
    printf("PASS: real SMTP contact email captured by Mailpit (%s), with correct From, To, Reply-To and HTML/text bodies.\n", $summary['ID']);
    exit(0);
}
throw new RuntimeException('Mailpit did not contain the submitted inquiry.');
