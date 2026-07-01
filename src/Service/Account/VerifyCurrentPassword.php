<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Single-purpose guard: does the given plaintext match the user's
 * currently-stored password hash?
 *
 * Used by self-service settings flows that need re-authentication before
 * mutating something sensitive (change password today, change email when
 * that section ships). Kept as its own service so the check is identical
 * across call sites and trivially mockable.
 *
 * Delegates to UserPasswordHasherInterface::isPasswordValid, which is
 * itself constant-time and handles the algorithm switching done by the
 * configured 'auto' hasher.
 */
final class VerifyCurrentPassword
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function verify(User $user, string $plain): bool
    {
        return $this->hasher->isPasswordValid($user, $plain);
    }
}