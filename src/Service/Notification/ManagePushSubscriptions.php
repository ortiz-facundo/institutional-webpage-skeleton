<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Administration\User;
use App\Entity\Notification\{NotificationDelivery, PushSubscription};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

final class ManagePushSubscriptions
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function list(User $user): array
    {
        return $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user, 'enabled' => true], ['id' => 'DESC']);
    }

    public function register(User $user, array $data): PushSubscription
    {
        $endpoint = $data['endpoint'] ?? null;
        $publicKey = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;
        // Explicit browser-service allowlist: never send server HTTP requests to
        // arbitrary user-supplied URLs. Expand intentionally for other browsers.
        if (!is_string($endpoint) || strlen($endpoint) > 2048 || !$this->isAllowedEndpoint($endpoint)
            || !is_string($publicKey) || !$this->validKey($publicKey, 65)
            || !str_starts_with(base64_decode(strtr($publicKey, '-_', '+/'), true) ?: '', "\x04")
            || !is_string($auth) || !$this->validKey($auth, 16)) {
            throw new BadRequestHttpException('Invalid Chrome push subscription.');
        }
        $existing = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpointHash' => hash('sha256', $endpoint)]);
        if ($existing !== null) {
            if ($existing->getUser()->getId() !== $user->getId()) {
                throw new ConflictHttpException('Unsubscribe this browser before registering another account.');
            }
            if ($existing->getPublicKey() !== $publicKey || $existing->getAuthSecret() !== $auth) {
                throw new ConflictHttpException('Recreate the browser subscription.');
            }
            $existing->enable();
            $this->em->flush();
            return $existing;
        }
        $label = $data['deviceLabel'] ?? 'Chrome';
        $subscription = new PushSubscription($user, $endpoint, $publicKey, $auth, is_string($label) ? mb_substr($label, 0, 120) : 'Chrome');
        $this->em->persist($subscription);
        $this->em->flush();
        return $subscription;
    }

    public function disable(User $user, int $id): void
    {
        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy(['id' => $id, 'user' => $user]) ?? throw new NotFoundHttpException();
        $subscription->disable();
        $this->em->createQuery('UPDATE App\Entity\Notification\NotificationDelivery d SET d.status = :skipped WHERE d.pushSubscription = :subscription AND d.status = :pending')
            ->execute(['skipped' => NotificationDelivery::STATUS_SKIPPED, 'subscription' => $subscription, 'pending' => NotificationDelivery::STATUS_PENDING]);
        $this->em->flush();
    }

    public function isAllowedEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        return is_array($url) && ($url['scheme'] ?? '') === 'https'
            && ($url['host'] ?? '') === 'fcm.googleapis.com'
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['fragment'])
            && (!isset($url['port']) || $url['port'] === 443)
            && str_starts_with($url['path'] ?? '', '/fcm/send/');
    }

    private function validKey(string $key, int $bytes): bool
    {
        return strlen($key) <= 100 && preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $key)
            && strlen(base64_decode(strtr($key, '-_', '+/'), true) ?: '') === $bytes;
    }
}
