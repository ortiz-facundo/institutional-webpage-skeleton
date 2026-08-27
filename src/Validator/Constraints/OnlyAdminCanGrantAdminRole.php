<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Form-level constraint: rejects a user-role assignment when the actor
 * tries to grant a Role whose permissions include an admin permission,
 * unless the actor themselves holds an admin permission.
 *
 * Admin status is determined by
 * {@see \App\Security\AdminGrantGuard::isAdminActor()}, which currently
 * looks at PERMISSION_IMPERSONATE_USERS (see
 * {@see \App\Security\Permission::adminPermissions()}).
 *
 * Applied via the standard `constraints` option on a form field. Runs
 * during $form->isValid() — no event listeners involved.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class OnlyAdminCanGrantAdminRole extends Constraint
{
    public string $message = 'app.administration.user.role_admin_only';
}
