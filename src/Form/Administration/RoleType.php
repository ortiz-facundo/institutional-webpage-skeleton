<?php

declare(strict_types=1);

namespace App\Form\Administration;

use App\Entity\Administration\Permission;
use App\Entity\Administration\Role;
use App\Repository\Administration\PermissionRepository;
use App\Security\Permission as Perm;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RoleType extends AbstractType
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Role name (e.g. ROLE_EDITOR)',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 3, max: 50),
                    new Assert\Regex('/^ROLE_[A-Z0-9_]+$/', 'Role names must be UPPER_SNAKE_CASE and start with ROLE_.'),
                ],
            ])
            ->add('permissions', EntityType::class, [
                'class'        => Permission::class,
                'choice_label' => 'name',
                'choices'      => $this->loadPermissionChoices(),
                'multiple'     => true,
                'expanded'     => true,
                'by_reference' => false,
                'constraints'  => [new Assert\Count(min: 1, minMessage: 'Assign at least one permission.')],
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
