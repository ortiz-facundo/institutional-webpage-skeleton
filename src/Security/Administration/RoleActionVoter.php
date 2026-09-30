<?php

declare(strict_types=1);

namespace App\Security\Administration;

use App\Entity\Administration\Role;
use App\Entity\Administration\User;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** Central eligibility and permission rules for administrative Role actions. */
final class RoleActionVoter extends Voter
{
    public const string DELETE = 'ADMINISTRATION_ROLE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::DELETE && $subject instanceof Role;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User || !$subject instanceof Role) {
            return false;
        }

        return in_array(Permission::PERMISSION_MANAGE_ROLES, $actor->getRoles(), true)
            && $subject->getUsers()->isEmpty();
    }
}
