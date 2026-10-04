<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Administration\User;
use App\Entity\Notification\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotificationInbox
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator, private readonly UrlGeneratorInterface $router) {}

    public function list(User $user, int $page = 1, bool $unread = false, int $limit = 20): array
    {
        $criteria = ['recipient' => $user];
        if ($unread) { $criteria['readAt'] = null; }
        return $this->em->getRepository(Notification::class)->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, (max(1, $page) - 1) * $limit);
    }

    public function count(User $user, bool $unread = false): int
    {
        $criteria = ['recipient' => $user];
        if ($unread) { $criteria['readAt'] = null; }
        return $this->em->getRepository(Notification::class)->count($criteria);
    }

    public function present(Notification $notification, ?string $locale = null): array
    {
        return [
            'id' => $notification->getId(),
            'title' => $this->translator->trans($notification->getTitleKey(), $notification->getTranslationParameters(), 'messages', $locale),
            'body' => $this->translator->trans($notification->getBodyKey(), $notification->getTranslationParameters(), 'messages', $locale),
            // Push clicks go through the authenticated inbox action to check ownership;
            // target controllers still enforce their own authorization. GET does not
            // mark read: the UI uses a separate CSRF-protected POST action.
            'url' => $this->router->generate('notification_open', ['id' => $notification->getId()]),
            'createdAt' => $notification->getCreatedAt()->format(DATE_ATOM),
            'unread' => $notification->getReadAt() === null,
        ];
    }

    public function findOwned(User $user, int $id): Notification
    {
        return $this->em->getRepository(Notification::class)->findOneBy(['id' => $id, 'recipient' => $user]) ?? throw new NotFoundHttpException();
    }

    public function markAsRead(User $user, int $id): void
    {
        $this->findOwned($user, $id)->markAsRead();
        $this->em->flush();
    }

    public function markAllAsRead(User $user): void
    {
        $this->em->createQuery('UPDATE App\Entity\Notification\Notification n SET n.readAt = :now WHERE n.recipient = :user AND n.readAt IS NULL')
            ->execute(['now' => new \DateTimeImmutable(), 'user' => $user]);
    }
}
