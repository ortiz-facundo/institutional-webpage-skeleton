<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Administration\User;

/**
 * A user is considered "an admin" if any of their roles grants at least
 * one permission in {@see Permission::adminPermissions()}. Used to deny
 * admin-to-admin impersonation (ImpersonateController) and to hide the
 * impersonate button on admin rows (UserController).
 *
 * The admin tier is an explicit permission allowlist, not a
 * `PERMISSION_MANAGE_*` name-prefix match: a downstream project's
 * catalog can reuse `MANAGE_*` for non-admin verbs (e.g.
 * `PERMISSION_MANAGE_MY_LEADS` for a seller role), which a prefix check
 * would misclassify as admin. Add new admin-tier permissions to
 * `Permission::adminPermissions()`, not here.
 */
final class AdminPolicy
{
    public static function isAdmin(User $user): bool
    {
        foreach ($user->getRoleEntities() as $role) {
            if ($role->isAdmin()) {
                return true;
            }
        }
        return false;
    }
}
