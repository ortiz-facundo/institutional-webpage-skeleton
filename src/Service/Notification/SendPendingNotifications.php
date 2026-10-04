<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Administration\User;
use App\Entity\Notification\NotificationDelivery;
use Doctrine\ORM\EntityManagerInterface;

final class SendPendingNotifications
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SendWebPushNotification $push) {}

    /** Caller must hold the application-wide worker lock. */
    public function execute(int $seconds = 45, int $limit = 50): array
    {
        $result = ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$this->push->isConfigured()) {
            throw new \RuntimeException('Push is disabled or VAPID keys are missing. Configure notification environment variables first.');
        }
        if (!extension_loaded('bcmath') && !extension_loaded('gmp')) {
            throw new \RuntimeException('Web Push requires BCMath or GMP in this Symfony runtime. Enable it for PHP CLI.');
        }
        $deadline = microtime(true) + $seconds;
        $ids = $this->em->createQueryBuilder()->select('d.id')->from(NotificationDelivery::class, 'd')
            ->where('d.status = :status')->andWhere('d.nextAttemptAt <= :now')->orderBy('d.id', 'ASC')
            ->setParameter('status', NotificationDelivery::STATUS_PENDING)->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults($limit)->getQuery()->getSingleColumnResult();
        foreach ($ids as $id) {
            // Reserve enough budget for a bounded network request and persistence.
            if (microtime(true) + 12 > $deadline) { break; }
            $this->em->clear();
            $delivery = $this->em->find(NotificationDelivery::class, $id);
            if ($delivery === null || $delivery->getStatus() !== NotificationDelivery::STATUS_PENDING) { continue; }
            $user = $delivery->getNotification()->getRecipient();
            $subscription = $delivery->getPushSubscription();
            $permission = $delivery->getNotification()->getRequiredPermission();
            if ($delivery->getNotification()->getArchivedAt() !== null
                || $user->getState() !== User::USER_STATE_ACTIVE || $subscription === null || !$subscription->isEnabled()
                || $subscription->getUser()->getId() !== $user->getId()
                || ($permission !== null && !in_array($permission, $user->getRoles(), true))) {
                $delivery->skip();
            } else {
                $delivery->startAttempt();
                // Persist attempts BEFORE I/O so repeated crashes cannot retry forever.
                $this->em->flush();
                if ($delivery->getAttempts() > 5) {
                    $delivery->fail('Maximum attempts exceeded after interrupted delivery.', false);
                } else {
                    try {
                        match ($delivery->getChannel()) {
                            NotificationDelivery::CHANNEL_WEB_PUSH => $this->push->execute($delivery),
                            default => $delivery->fail('Unsupported channel.', false),
                        };
                    } catch (\Throwable $e) {
                        $delivery->fail('Push processing failed ('.$e::class.').', !($e instanceof \InvalidArgumentException));
                    }
                }
            }
            $this->em->flush();
            ++$result['processed'];
            if ($delivery->getStatus() !== NotificationDelivery::STATUS_PENDING) { ++$result[$delivery->getStatus()]; }
        }
        return $result;
    }
}
