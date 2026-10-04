<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Administration\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
class PushSubscription
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Column(type: 'text')]
    private string $endpoint;
    #[ORM\Column(length: 64, unique: true)]
    private string $endpointHash;
    #[ORM\Column(length: 100)]
    private string $publicKey;
    #[ORM\Column(length: 50)]
    private string $authSecret;
    #[ORM\Column(length: 120)]
    private string $deviceLabel;
    #[ORM\Column]
    private bool $enabled = true;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authSecret, string $deviceLabel)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authSecret = $authSecret;
        $this->deviceLabel = $deviceLabel;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthSecret(): string { return $this->authSecret; }
    public function getDeviceLabel(): string { return $this->deviceLabel; }
    public function isEnabled(): bool { return $this->enabled; }
    public function disable(): void { $this->enabled = false; }
    public function enable(): void { $this->enabled = true; }
}
