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

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PERMISSION_VIEW_BACKOFFICE,
            self::PERMISSION_MANAGE_USERS,
            self::PERMISSION_MANAGE_ROLES,
            self::PERMISSION_MANAGE_PERMISSIONS,
            self::PERMISSION_IMPERSONATE_USERS,
        ];
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
}
