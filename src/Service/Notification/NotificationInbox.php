<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Administration\User;
use App\Entity\Notification\Notification;
use App\Entity\Notification\NotificationDelivery;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotificationInbox
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator, private readonly UrlGeneratorInterface $router) {}

    public function list(User $user, int $page = 1, bool $unread = false, int $limit = 20, bool $archived = false): array
    {
        return $this->query($user, $unread, $archived)->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)->setFirstResult((max(1, $page) - 1) * $limit)->getQuery()->getResult();
    }

    public function count(User $user, bool $unread = false, bool $archived = false): int
    {
        return (int) $this->query($user, $unread, $archived)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
    }

    private function query(User $user, bool $unread, bool $archived): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->select('n')->from(Notification::class, 'n')
            ->where('n.recipient = :user')->setParameter('user', $user)
            ->andWhere($archived ? 'n.archivedAt IS NOT NULL' : 'n.archivedAt IS NULL');
        if ($unread) { $query->andWhere('n.readAt IS NULL'); }
        return $query;
    }

    public function present(Notification $notification, ?string $locale = null): array
    {
        $module = NotificationIcon::module($notification->getType());
        $moduleKey = 'app.notification.module.'.$module;
        $moduleLabel = $this->translator->trans($moduleKey, [], 'messages', $locale);
        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'icon' => NotificationIcon::forType($notification->getType()),
            'module' => $module,
            // Untranslated modules fall back to the capitalized prefix.
            'moduleLabel' => $moduleLabel === $moduleKey ? ucfirst($module) : $moduleLabel,
            'title' => $this->translator->trans($notification->getTitleKey(), $notification->getTranslationParameters(), 'messages', $locale),
            'body' => $this->translator->trans($notification->getBodyKey(), $notification->getTranslationParameters(), 'messages', $locale),
            // Push clicks go through the authenticated inbox action to check ownership;
            // target controllers still enforce their own authorization. GET does not
            // mark read: the UI uses a separate CSRF-protected POST action.
            'url' => $this->router->generate('notification_open', ['id' => $notification->getId()]),
            'createdAt' => $notification->getCreatedAt()->format(DATE_ATOM),
            'unread' => $notification->getReadAt() === null,
            'archived' => $notification->getArchivedAt() !== null,
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
        $this->em->createQuery('UPDATE App\Entity\Notification\Notification n SET n.readAt = :now WHERE n.recipient = :user AND n.readAt IS NULL AND n.archivedAt IS NULL')
            ->execute(['now' => new \DateTimeImmutable(), 'user' => $user]);
    }

    public function archive(User $user, int $id): void
    {
        $notification = $this->findOwned($user, $id);
        // Expected validation failures stay outside wrapInTransaction, which
        // closes the EntityManager when a callback throws. Read state is monotonic.
        if ($notification->getReadAt() === null) {
            throw new \DomainException('app.notification.archive_requires_read');
        }
        $this->em->wrapInTransaction(function () use ($notification): void {
            $this->em->refresh($notification, LockMode::PESSIMISTIC_WRITE);
            if ($notification->getReadAt() === null) {
                throw new \DomainException('app.notification.archive_requires_read');
            }
            $notification->setArchivedAt($notification->getArchivedAt() ?? new \DateTimeImmutable());
            // Cancelling immediately prevents a restore before the next cron tick
            // from resurrecting delivery rows. Acceptance already in flight cannot
            // be recalled; the worker also checks archivedAt before starting I/O.
            $this->em->createQuery('UPDATE App\Entity\Notification\NotificationDelivery d SET d.status = :skipped WHERE d.notification = :notification AND d.status = :pending')
                ->execute(['skipped' => NotificationDelivery::STATUS_SKIPPED, 'notification' => $notification, 'pending' => NotificationDelivery::STATUS_PENDING]);
        });
    }

    public function restore(User $user, int $id): void
    {
        $notification = $this->findOwned($user, $id);
        $this->em->wrapInTransaction(function () use ($notification): void {
            $this->em->refresh($notification, LockMode::PESSIMISTIC_WRITE);
            $notification->setArchivedAt(null);
            // Preserve readAt and existing delivery outcomes; no new push is queued.
        });
    }
}
