<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Form-level constraint: rejects a role-permission assignment when the
 * actor tries to attach an admin permission (per
 * {@see \App\Security\Permission::adminPermissions()}) to a role, unless
 * the actor themselves holds an admin permission.
 *
 * Mirror of {@see OnlyAdminCanGrantAdminRole}, but for the role-editor
 * form: a manager-tier actor can edit roles but cannot grant them admin
 * powers.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class OnlyAdminCanGrantAdminPermission extends Constraint
{
    public string $message = 'app.administration.role.permission_admin_only';
}
