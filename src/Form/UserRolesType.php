<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserRolesType extends AbstractType
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('roleEntities', EntityType::class, [
            'class'        => Role::class,
            'choice_label' => 'name',
            'choices'      => $this->roles->findBy([], ['name' => 'ASC']),
            'multiple'     => true,
            'expanded'     => true,
            'by_reference' => false,
            'property_path' => 'roles',
            'getter'       => static fn ($user) => $user->getRoleEntities(),
            'constraints'  => [new Assert\Count(min: 1, minMessage: 'Assign at least one role.')],
            'label'        => 'Roles',
        ]);

        $builder->get('roleEntities')->addModelTransformer(new CallbackTransformer(
            static function ($collection) {
                return $collection === null ? [] : iterator_to_array($collection);
            },
            static function ($array) {
                $col = new ArrayCollection();
                foreach ($array ?? [] as $item) {
                    $col->add($item);
                }
                return $col;
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\User::class,
        ]);
    }
}
