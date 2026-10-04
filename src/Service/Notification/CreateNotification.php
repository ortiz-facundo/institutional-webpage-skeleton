<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Administration\User;
use App\Entity\Notification\{Notification, NotificationDelivery, PushSubscription};
use App\Security\Permission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

final class CreateNotification
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly RouterInterface $router) {}

    /**
     * Joins an existing DBAL transaction; callers must commit/rollback their business
     * operation as a unit. Flushes the current unit of work, never sends external I/O.
     */
    public function execute(User $recipient, string $type, string $titleKey, string $bodyKey, array $parameters = [], string $destinationRoute = 'backoffice_dashboard', array $destinationParameters = [], array $channels = [NotificationDelivery::CHANNEL_WEB_PUSH], ?string $deduplicationKey = null, ?string $requiredPermission = null): Notification
    {
        if ($recipient->getId() === null || $recipient->getState() !== User::USER_STATE_ACTIVE) {
            throw new \InvalidArgumentException('Notifications require a persisted active recipient.');
        }
        if ($requiredPermission !== null && !Permission::isValid($requiredPermission)) {
            throw new \InvalidArgumentException('Unknown notification permission.');
        }
        if ($type === '' || strlen($type) > 100 || strlen($titleKey) > 255 || strlen($bodyKey) > 255) {
            throw new \InvalidArgumentException('Invalid notification content.');
        }
        if (array_diff($channels, [NotificationDelivery::CHANNEL_WEB_PUSH])) {
            throw new \InvalidArgumentException('Unsupported notification channel.');
        }
        $this->router->generate($destinationRoute, $destinationParameters);
        $eventKey = hash('sha256', $type.'|'.($deduplicationKey ?? bin2hex(random_bytes(16))));

        return $this->em->wrapInTransaction(function () use ($recipient, $type, $titleKey, $bodyKey, $parameters, $destinationRoute, $destinationParameters, $channels, $eventKey, $requiredPermission): Notification {
            // Serialize publication for this recipient so concurrent duplicate events
            // return the existing row instead of closing the EM on a unique violation.
            $this->em->lock($recipient, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(Notification::class)->findOneBy(['recipient' => $recipient, 'eventKey' => $eventKey]);
            if ($existing !== null) {
                return $existing;
            }
            $notification = new Notification($recipient, $type, $eventKey, $titleKey, $bodyKey, $parameters, $destinationRoute, $destinationParameters, $requiredPermission);
            $this->em->persist($notification);
            if (in_array(NotificationDelivery::CHANNEL_WEB_PUSH, $channels, true)) {
                foreach ($this->em->getRepository(PushSubscription::class)->findBy(['user' => $recipient, 'enabled' => true]) as $subscription) {
                    $this->em->persist(new NotificationDelivery($notification, $subscription));
                }
            }
            return $notification;
        });
    }
}
