<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification\NotificationDelivery;
use Minishlink\WebPush\{Subscription, WebPush};
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SendWebPushNotification
{
    public function __construct(
        private readonly NotificationInbox $inbox,
        private readonly ManagePushSubscriptions $subscriptions,
        #[Autowire(env: 'NOTIFICATION_VAPID_PUBLIC_KEY')] private readonly string $publicKey,
        #[Autowire(env: 'NOTIFICATION_VAPID_PRIVATE_KEY')] private readonly string $privateKey,
        #[Autowire(env: 'NOTIFICATION_VAPID_SUBJECT')] private readonly string $subject,
        #[Autowire(env: 'bool:NOTIFICATION_PUSH_ENABLED')] private readonly bool $enabled,
    ) {}

    public function isConfigured(): bool
    {
        return $this->enabled && $this->publicKey !== '' && $this->privateKey !== '';
    }

    public function getPublicKey(): string { return $this->isConfigured() ? $this->publicKey : ''; }

    public function execute(NotificationDelivery $delivery): void
    {
        $subscription = $delivery->getPushSubscription();
        if ($subscription === null || !$this->subscriptions->isAllowedEndpoint($subscription->getEndpoint())) {
            $delivery->fail('Invalid or removed push endpoint.', false);
            return;
        }
        $notification = $delivery->getNotification();
        $payload = $this->inbox->present($notification, $notification->getRecipient()->getLocale() ?? 'es');
        unset($payload['createdAt'], $payload['unread']);
        $payload['title'] = mb_substr($payload['title'], 0, 100);
        $payload['body'] = mb_substr($payload['body'], 0, 200);
        $webPush = new WebPush(['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]], ['TTL' => 3600, 'urgency' => 'normal'], 10, ['connect_timeout' => 5, 'allow_redirects' => false]);
        $report = $webPush->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->getEndpoint(),
            'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()],
        ]), json_encode($payload, JSON_THROW_ON_ERROR));
        if ($report->isSuccess()) {
            $delivery->accept();
            return;
        }
        if ($report->isSubscriptionExpired()) { $subscription->disable(); }
        $status = $report->getResponse()?->getStatusCode();
        // Do not persist provider response text: it may contain capability URLs.
        $delivery->fail('Push service rejected delivery (HTTP '.($status ?? 'network').').', $status === null || $status === 429 || $status >= 500);
    }
}
