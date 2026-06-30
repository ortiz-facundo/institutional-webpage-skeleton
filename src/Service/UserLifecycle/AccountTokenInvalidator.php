<?php

declare(strict_types=1);

namespace App\Service\UserLifecycle;

use App\Entity\User;
use App\Repository\AccountConfirmationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes active confirmation tokens for a user. Used:
 *   - by the issuer (rotating before issuing a new one);
 *   - by ChangeUserStateToDisabled (so a freshly disabled user can't
 *     complete a stale invitation).
 */
final class AccountTokenInvalidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountConfirmationTokenRepository $repo,
    ) {
    }

    public function invalidateAllForUser(User $user): void
    {
        foreach ($this->repo->findActiveForUser($user) as $token) {
            $this->em->remove($token);
        }
        $this->em->flush();
    }
}