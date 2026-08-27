<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Entity\Administration\Role;
use App\Security\AdminGrantGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Thin validator over {@see OnlyAdminCanGrantAdminRole}. Delegates the
 * policy to {@see AdminGrantGuard}, then attaches a FormError at the
 * specific offending checkbox via atPath('[roleId]') so the error
 * renders inline next to that input.
 */
final class OnlyAdminCanGrantAdminRoleValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AdminGrantGuard $guard,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof OnlyAdminCanGrantAdminRole) {
            throw new UnexpectedTypeException($constraint, OnlyAdminCanGrantAdminRole::class);
        }

        if ($value === null || (is_iterable($value) && !count($value))) {
            return;
        }

        $actor = $this->security->getUser();

        foreach ($this->guard->findDisallowedRoles($actor, is_array($value) ? $value : iterator_to_array($value)) as $role) {
            \assert($role instanceof Role);
            $this->guard->logRejectedRole($actor, $role);
            $this->context->buildViolation(
                $this->translator->trans($constraint->message, ['%role%' => $role->getName()])
            )
                ->atPath('[' . $role->getId() . ']')
                ->addViolation();
        }
    }
}
