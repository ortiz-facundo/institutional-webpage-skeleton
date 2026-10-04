<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\Index(columns: ['status', 'next_attempt_at'], name: 'notification_delivery_due_idx')]
#[ORM\UniqueConstraint(name: 'notification_device_unique', columns: ['notification_id', 'push_subscription_id'])]
class NotificationDelivery
{
    public const string CHANNEL_WEB_PUSH = 'web_push';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PushSubscription $pushSubscription;
    #[ORM\Column(length: 30)]
    private string $channel = self::CHANNEL_WEB_PUSH;
    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;
    #[ORM\Column]
    private int $attempts = 0;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $nextAttemptAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    public function __construct(Notification $notification, PushSubscription $subscription)
    {
        $this->notification = $notification;
        $this->pushSubscription = $subscription;
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getNotification(): Notification { return $this->notification; }
    public function getPushSubscription(): ?PushSubscription { return $this->pushSubscription; }
    public function getChannel(): string { return $this->channel; }
    public function getAttempts(): int { return $this->attempts; }
    public function getStatus(): string { return $this->status; }
    public function startAttempt(): void { ++$this->attempts; }
    public function accept(): void
    {
        $this->status = self::STATUS_ACCEPTED;
        $this->acceptedAt = new \DateTimeImmutable();
        $this->lastError = null;
    }
    public function skip(): void { $this->status = self::STATUS_SKIPPED; }
    public function fail(string $error, bool $retryable): void
    {
        $this->lastError = mb_substr($error, 0, 500);
        if (!$retryable || $this->attempts >= 5) {
            $this->status = self::STATUS_FAILED;
            return;
        }
        $delay = min(3600, 60 * (2 ** max(0, $this->attempts - 1)));
        $this->nextAttemptAt = new \DateTimeImmutable(sprintf('+%d seconds', $delay));
    }
}
