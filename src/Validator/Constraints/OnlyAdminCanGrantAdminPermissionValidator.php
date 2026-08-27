<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Entity\Administration\Permission;
use App\Security\AdminGrantGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Thin validator over {@see OnlyAdminCanGrantAdminPermission}. Delegates
 * the policy to {@see AdminGrantGuard}, then attaches a FormError at the
 * specific offending checkbox via atPath('[permissionId]').
 */
final class OnlyAdminCanGrantAdminPermissionValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AdminGrantGuard $guard,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof OnlyAdminCanGrantAdminPermission) {
            throw new UnexpectedTypeException($constraint, OnlyAdminCanGrantAdminPermission::class);
        }

        if ($value === null || (is_iterable($value) && !count($value))) {
            return;
        }

        $actor = $this->security->getUser();

        foreach ($this->guard->findDisallowedPermissions($actor, is_array($value) ? $value : iterator_to_array($value)) as $perm) {
            \assert($perm instanceof Permission);
            $this->guard->logRejectedPermission($actor, $perm);
            $this->context->buildViolation(
                $this->translator->trans($constraint->message, ['%permission%' => $perm->getName()])
            )
                ->atPath('[' . $perm->getId() . ']')
                ->addViolation();
        }
    }
}
