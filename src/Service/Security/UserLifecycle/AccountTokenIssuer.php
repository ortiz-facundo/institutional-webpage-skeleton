<?php

declare(strict_types=1);

namespace App\Service\Security\UserLifecycle;

use App\Entity\Security\AccountConfirmationToken;
use App\Entity\Administration\User;
use App\Repository\Security\AccountConfirmationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and invalidates account-confirmation tokens.
 *
 * Tokens live for two days (matches the AccountConfirmationController's
 * behaviour). A new token always invalidates the user's prior active
 * tokens, so a Pending user only ever has one usable link at a time —
 * important for the "admin changes a Pending user's email" flow.
 */
final class AccountTokenIssuer
{
    /**
     * Token TTL. Two days per the project decision.
     */
    public const string TOKEN_TTL = 'P2D';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountConfirmationTokenRepository $repo,
        private readonly AccountTokenInvalidator $invalidator,
    ) {
    }

    public function issue(User $user): AccountConfirmationToken
    {
        $this->invalidator->invalidateAllForUser($user);

        $selector = $this->randomToken(16);
        $secret   = $this->randomToken(32);
        $hash     = hash('sha256', $secret);
        $expires  = (new \DateTimeImmutable())->add(new \DateInterval(self::TOKEN_TTL));

        $token = new AccountConfirmationToken($user, $selector, $hash, $expires);
        $token->setRawSecret($secret);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    /**
     * @return list<AccountConfirmationToken>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->repo->findActiveForUser($user);
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}