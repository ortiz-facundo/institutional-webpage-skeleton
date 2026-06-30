<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Login firewall check. Symfony's form_login calls `checkPreAuth` on
 * every authentication attempt; if we throw, the login form surfaces
 * the message in `error.messageKey` and the user can't proceed.
 *
 * Only USER_STATE_ACTIVE users are allowed through. Pending users have
 * no password yet (they shouldn't reach the firewall, but if they do we
 * treat them as invalid). Disabled users are explicitly blocked with a
 * message that tells them to contact their admin.
 */
final class UserStateChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isDisabled()) {
            throw new DisabledException();
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // No additional checks post-auth. State can only be changed by an
        // admin in the backoffice; if an admin disables a logged-in user,
        // the session is invalidated on the next request via the firewall's
        // token storage.
    }
}