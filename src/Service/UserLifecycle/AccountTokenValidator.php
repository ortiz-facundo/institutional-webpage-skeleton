<?php

declare(strict_types=1);

namespace App\Service\UserLifecycle;

use App\Entity\AccountConfirmationToken;
use App\Repository\AccountConfirmationTokenRepository;

/**
 * Validates a (selector, secret) pair coming from the URL. Returns the
 * token only if it's still active (not consumed, not expired) AND the
 * secret matches.
 *
 * The lookup is constant-time via hash_equals() so it can't be used for a
 * timing oracle on the selector.
 */
final class AccountTokenValidator
{
    public function __construct(private readonly AccountConfirmationTokenRepository $repo)
    {
    }

    public function findValid(string $selector, string $secret): ?AccountConfirmationToken
    {
        $token = $this->repo->findOneBy(['selector' => $selector]);
        if ($token === null) {
            return null;
        }
        if (!$token->isUsable(new \DateTimeImmutable())) {
            return null;
        }
        if (!$token->verify($secret)) {
            return null;
        }
        return $token;
    }

    public function consume(AccountConfirmationToken $token): void
    {
        $token->consume();
    }
}