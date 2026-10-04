<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Administration\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['recipient_id', 'created_at'], name: 'notification_inbox_idx')]
#[ORM\Index(columns: ['recipient_id', 'read_at'], name: 'notification_unread_idx')]
#[ORM\Index(columns: ['recipient_id', 'archived_at', 'created_at'], name: 'notification_archive_idx')]
#[ORM\UniqueConstraint(name: 'notification_event_unique', columns: ['recipient_id', 'event_key'])]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 64)]
    private string $eventKey;

    #[ORM\Column(length: 255)]
    private string $titleKey;

    #[ORM\Column(length: 255)]
    private string $bodyKey;

    #[ORM\Column(type: 'json')]
    private array $translationParameters;

    #[ORM\Column(length: 150)]
    private string $destinationRoute;

    #[ORM\Column(type: 'json')]
    private array $destinationParameters;

    // Optional downstream permission, always supplied from App\Security\Permission.
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $requiredPermission;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(User $recipient, string $type, string $eventKey, string $titleKey, string $bodyKey, array $parameters, string $route, array $routeParameters, ?string $requiredPermission)
    {
        $this->recipient = $recipient;
        $this->type = $type;
        $this->eventKey = $eventKey;
        $this->titleKey = $titleKey;
        $this->bodyKey = $bodyKey;
        $this->translationParameters = $parameters;
        $this->destinationRoute = $route;
        $this->destinationParameters = $routeParameters;
        $this->requiredPermission = $requiredPermission;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): User { return $this->recipient; }
    public function getType(): string { return $this->type; }
    public function getTitleKey(): string { return $this->titleKey; }
    public function getBodyKey(): string { return $this->bodyKey; }
    public function getTranslationParameters(): array { return $this->translationParameters; }
    public function getDestinationRoute(): string { return $this->destinationRoute; }
    public function getDestinationParameters(): array { return $this->destinationParameters; }
    public function getRequiredPermission(): ?string { return $this->requiredPermission; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(?\DateTimeImmutable $archivedAt): void { $this->archivedAt = $archivedAt; }
    public function markAsRead(): void { $this->readAt ??= new \DateTimeImmutable(); }
}
