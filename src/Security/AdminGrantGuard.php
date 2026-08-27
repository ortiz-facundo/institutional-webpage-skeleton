<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Administration\Permission;
use App\Entity\Administration\Role;
use App\Entity\Administration\User;
use App\Security\Permission as SecurityPermission;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Single source of truth for the "managers cannot grant admin permissions"
 * policy. Held by the two Symfony form validators that enforce it:
 *
 *   - {@see \App\Validator\Constraints\OnlyAdminCanGrantAdminRoleValidator}
 *   - {@see \App\Validator\Constraints\OnlyAdminCanGrantAdminPermissionValidator}
 *
 * The two validators are thin wrappers around this class — they call
 * findDisallowed*() to enumerate the offenders, then attach a Symfony
 * FormError at the offending checkbox via atPath('[id]'). They also call
 * logRejected*() so every attempt is audit-logged.
 *
 * "Admin" here is defined by {@see Permission::adminPermissions()}: a user
 * holding any of those permissions is considered an admin actor and may
 * grant anything. Everyone else is restricted to non-admin roles/perms.
 */
final class AdminGrantGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isAdminActor(?UserInterface $actor): bool
    {
        if (!$actor instanceof User) {
            return false;
        }
        return $this->security->isGranted(SecurityPermission::PERMISSION_IMPERSONATE_USERS);
    }

    /**
     * @param iterable<mixed> $roles
     * @return list<Role>
     */
    public function findDisallowedRoles(?UserInterface $actor, iterable $roles): array
    {
        if ($this->isAdminActor($actor)) {
            return [];
        }
        $out = [];
        foreach ($roles as $role) {
            if ($role instanceof Role && $role->isAdmin()) {
                $out[] = $role;
            }
        }
        return $out;
    }

    /**
     * @param iterable<mixed> $permissions
     * @return list<Permission>
     */
    public function findDisallowedPermissions(?UserInterface $actor, iterable $permissions): array
    {
        if ($this->isAdminActor($actor)) {
            return [];
        }
        $out = [];
        foreach ($permissions as $perm) {
            if ($perm instanceof Permission && SecurityPermission::isAdminPermission($perm->getName())) {
                $out[] = $perm;
            }
        }
        return $out;
    }

    public function logRejectedRole(?UserInterface $actor, Role $role): void
    {
        $this->auditLogger->warning('user.role_assignment.rejected.admin_role', [
            'actor_id'    => $actor instanceof User ? $actor->getId() : null,
            'actor_email' => $actor instanceof User ? $actor->getEmail() : null,
            'role_id'     => $role->getId(),
            'role_name'   => $role->getName(),
        ]);
    }

    public function logRejectedPermission(?UserInterface $actor, Permission $permission): void
    {
        $this->auditLogger->warning('role.permission_grant.rejected.admin_permission', [
            'actor_id'        => $actor instanceof User ? $actor->getId() : null,
            'actor_email'     => $actor instanceof User ? $actor->getEmail() : null,
            'permission_id'   => $permission->getId(),
            'permission_name' => $permission->getName(),
        ]);
    }
}
