<?php

declare(strict_types=1);

namespace App\Security;

final class Permission
{
    public const string PERMISSION_VIEW_BACKOFFICE     = 'PERMISSION_VIEW_BACKOFFICE';
    public const string PERMISSION_MANAGE_USERS        = 'PERMISSION_MANAGE_USERS';
    public const string PERMISSION_MANAGE_ROLES        = 'PERMISSION_MANAGE_ROLES';
    public const string PERMISSION_MANAGE_PERMISSIONS  = 'PERMISSION_MANAGE_PERMISSIONS';
    public const string PERMISSION_IMPERSONATE_USERS   = 'PERMISSION_IMPERSONATE_USERS';

    /**
     * Canonical permission catalog grouped by the module that owns them.
     * Group order is the rendering order. Adding a new permission: pick
     * the right group (add a new group key if needed) and append the
     * constant; `all()` flattens this in declaration order. Add a
     * `permission_group.<group>.label` translation key for the new group.
     *
     * The `admin` group is the privileged tier: anyone holding any of
     * its permissions is treated as an admin actor by
     * {@see Role::isAdmin()} / {@see AdminPolicy::isAdmin()} (used to
     * deny admin-to-admin impersonation and hide the impersonate
     * button). The `administration` group below is manager-tier:
     * roles that may manage users/roles/permissions but aren't
     * themselves privileged enough to be exempt from impersonation.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'admin' => [
                self::PERMISSION_IMPERSONATE_USERS,
            ],
            'administration' => [
                self::PERMISSION_MANAGE_USERS,
                self::PERMISSION_MANAGE_ROLES,
                self::PERMISSION_MANAGE_PERMISSIONS,
            ],
            'backoffice' => [
                self::PERMISSION_VIEW_BACKOFFICE,
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::groups() as $perms) {
            foreach ($perms as $name) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return array<string, string> value => label (same string; placeholder for future i18n) */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::all() as $name) {
            $choices[$name] = $name;
        }
        return $choices;
    }

    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * The set of permissions that mark a role or actor as
     * "administrative" — see {@see Role::isAdmin()} / {@see AdminPolicy}.
     * Intentionally NOT derived from a `PERMISSION_MANAGE_*` name
     * prefix: a downstream project's catalog can reuse `MANAGE_*` for
     * non-admin verbs (e.g. `PERMISSION_MANAGE_MY_LEADS` for a seller
     * role), which a prefix check would misclassify as admin. Adding a
     * new admin-tier permission: append it here AND to the `admin`
     * group in groups() above.
     *
     * @return list<string>
     */
    public static function adminPermissions(): array
    {
        return [
            self::PERMISSION_IMPERSONATE_USERS,
        ];
    }

    public static function isAdminPermission(string $name): bool
    {
        return in_array($name, self::adminPermissions(), true);
    }

    public static function moduleFor(string $name): ?string
    {
        foreach (self::groups() as $module => $perms) {
            if (in_array($name, $perms, true)) {
                return $module;
            }
        }
        return null;
    }
}
