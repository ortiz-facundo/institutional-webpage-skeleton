<?php

declare(strict_types=1);

namespace App\Security\Administration;

use App\Entity\Administration\User;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** Central eligibility and permission rules for administrative User actions. */
final class UserActionVoter extends Voter
{
    public const string DELETE = 'ADMINISTRATION_USER_DELETE';
    public const string IMPERSONATE = 'ADMINISTRATION_USER_IMPERSONATE';
    public const string RESEND_CONFIRMATION = 'ADMINISTRATION_USER_RESEND_CONFIRMATION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof User && in_array($attribute, [
            self::DELETE,
            self::IMPERSONATE,
            self::RESEND_CONFIRMATION,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User || !$subject instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::DELETE => $this->hasPermission($actor, Permission::PERMISSION_IMPERSONATE_USERS),
            self::IMPERSONATE => $this->hasPermission($actor, Permission::PERMISSION_IMPERSONATE_USERS)
                && $actor->getId() !== $subject->getId()
                && $subject->isActive()
                && !$subject->isAdmin(),
            self::RESEND_CONFIRMATION => $this->hasPermission($actor, Permission::PERMISSION_MANAGE_USERS)
                && $subject->isPending(),
            default => false,
        };
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $user->getRoles(), true);
    }
}
