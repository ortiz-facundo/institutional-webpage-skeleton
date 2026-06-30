<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountConfirmationTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-time token used to confirm a freshly-created account. The token has
 * two parts: a public `selector` (16 random bytes, base64url, used in the
 * URL) and a private `secret` (32 random bytes, only ever sent via email,
 * never stored — only its sha256 is). The selector+secret pattern lets us
 * safely invalidate tokens server-side without storing raw values.
 *
 * The URL format is /account/confirm?selector=…&secret=… — both parts must
 * match for the token to be usable.
 */
#[ORM\Entity(repositoryClass: AccountConfirmationTokenRepository::class)]
#[ORM\Table(name: 'account_confirmation_token')]
#[ORM\Index(columns: ['user_id'], name: 'idx_token_user')]
class AccountConfirmationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $selector;

    /**
     * sha256 of the secret. Compared via hash_equals() so the lookup is
     * constant-time.
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $hashedToken;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * The raw secret, populated by AccountTokenIssuer right after
     * construction so the controller / mailer can hand it out in the URL
     * without a DB round-trip. Doctrine never persists this — it's marked
     * `transient` via getSecret/setSecret and not declared as a column.
     */
    private ?string $rawSecret = null;

    public function __construct(User $user, string $selector, string $hashedToken, \DateTimeImmutable $expiresAt)
    {
        $this->user        = $user;
        $this->selector    = $selector;
        $this->hashedToken = $hashedToken;
        $this->expiresAt   = $expiresAt;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setRawSecret(string $secret): void
    {
        $this->rawSecret = $secret;
    }

    public function getRawSecret(): ?string
    {
        return $this->rawSecret;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $this->expiresAt > $now;
    }

    public function consume(): void
    {
        $this->consumedAt = new \DateTimeImmutable();
    }

    public function verify(string $secret): bool
    {
        return hash_equals($this->hashedToken, hash('sha256', $secret));
    }
}