<?php

declare(strict_types=1);

namespace App\Form\Administration;

use App\Entity\Administration\Permission;
use App\Entity\Administration\Role;
use App\Repository\Administration\PermissionRepository;
use App\Security\Permission as Perm;
use App\Validator\Constraints\OnlyAdminCanGrantAdminPermission;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class RoleType extends AbstractType
{
    public function __construct(
        private readonly PermissionRepository $permissions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => $this->translator->trans('app.administration.form.role.name'),
                'constraints' => [
                    new Assert\NotBlank(message: $this->translator->trans('app.shared.validators.required')),
                    new Assert\Length(min: 3, max: 50),
                    new Assert\Regex('/^ROLE_[A-Z0-9_]+$/', $this->translator->trans('app.role.name.invalid_format')),
                ],
            ])
            ->add('permissions', EntityType::class, [
                'class'        => Permission::class,
                'choice_label' => fn (Permission $p) => $this->translator->trans('permission.' . $p->getName() . '.label'),
                'group_by'     => fn (Permission $p) => Perm::moduleFor($p->getName()) ?? 'other',
                'choices'      => $this->loadPermissionChoices(),
                'multiple'     => true,
                'expanded'     => true,
                'by_reference' => false,
                'label'        => $this->translator->trans('app.administration.form.role.permissions'),
                'block_prefix' => 'permission_picker',
                'constraints'  => [
                    new Assert\Count(min: 1, minMessage: $this->translator->trans('app.role.permission.min')),
                    new OnlyAdminCanGrantAdminPermission(),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Role::class,
        ]);
    }

    /** @return list<Permission> */
    private function loadPermissionChoices(): array
    {
        $out = [];
        foreach (Perm::all() as $name) {
            $p = $this->permissions->findOneByName($name);
            if ($p !== null) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
