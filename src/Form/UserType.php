<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\RoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for creating and editing a User from the backoffice.
 *
 * Notes:
 *  - `plainPassword` is `mapped: false`. The hash happens in the controller's
 *    onFormSubmit hook (only when the field is non-empty). Required on
 *    create, optional on edit.
 *  - `roleEntities` writes to User::$roles via a POST_SUBMIT event listener
 *    that diffs the form's data against the entity's current roles. This
 *    sidesteps the Doctrine+EntityType+Symfony Form quirk where the
 *    data mapper iterates raw submitted values during a flow where
 *    `User::getRoles()` (string[]) is reachable from the property path
 *    "roles" via the default accessor — and ends up passing strings to
 *    addRole() and crashing the flush.
 *  - No `property_path`, no `getter`, no `by_reference` on roleEntities.
 *    We bypass the form's automatic data mapping for the roles field
 *    entirely and handle assignment in the listener.
 */
class UserType extends AbstractType
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                ],
            ])
            ->add('name', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 120)],
            ])
            // plainPassword is added in the PRE_SET_DATA listener below so
            // we can configure it per create vs. edit.
            ->add('roleEntities', EntityType::class, [
                'class'        => \App\Entity\Role::class,
                'choice_label' => 'name',
                'choices'      => $this->roles->findBy([], ['name' => 'ASC']),
                'multiple'     => true,
                'expanded'     => true,
                'mapped'       => false,
                'constraints'  => [new Assert\Count(min: 1, minMessage: 'Assign at least one role.')],
                'label'        => 'Roles',
            ])
        ;

        // Create vs. edit detection: a User is "new" (no id) on the create
        // form, "existing" on the edit form. PRE_SET_DATA fires before the
        // form's data has been stored, so the listener can read the incoming
        // data and configure the password field accordingly. We re-add the
        // child with the right constraints because FormConfigInterface is
        // read-only at runtime (no setRequired).
        //
        // The roleEntities field is `mapped: false`, so the form's automatic
        // child-data propagation sets the field to `null` (after our PRE_SET_DATA
        // listener would have set the initial data). We use POST_SET_DATA — which
        // fires AFTER the propagation — to set the actual current roles.
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::PRE_SET_DATA,
            function (\Symfony\Component\Form\FormEvent $event) {
                $user = $event->getData();
                $isCreate = !$user instanceof User || $user->getId() === null;
                if ($isCreate) {
                    $constraints = [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')];
                    $help = 'At least 8 characters.';
                    $required = true;
                } else {
                    $constraints = [new Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')];
                    $help = 'Leave blank to keep the current password.';
                    $required = false;
                }
                $event->getForm()->add('plainPassword', \Symfony\Component\Form\Extension\Core\Type\PasswordType::class, [
                    'mapped'      => false,
                    'required'    => $required,
                    'constraints' => $constraints,
                    'help'        => $help,
                    'label'       => 'Password',
                ]);
            }
        );

        // Pre-populate the roleEntities field with the entity's current
        // roles. POST_SET_DATA runs AFTER the form has propagated its data
        // to its children — so `mapped: false` children, which the propagation
        // sets to `null`, are still recoverable here.
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::POST_SET_DATA,
            function (\Symfony\Component\Form\FormEvent $event) {
                $user = $event->getData();
                if ($user instanceof User && $user->getId() !== null) {
                    $event->getForm()->get('roleEntities')->setData($user->getRoleEntities()->toArray());
                }
            }
        );

        // Apply the chosen roles to the entity after the form is fully
        // bound. We do this in POST_SUBMIT so the form's own data mapper
        // never touches the User::$roles relation. See the class docblock
        // for the rationale.
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::POST_SUBMIT,
            function (\Symfony\Component\Form\FormEvent $event) {
                $form = $event->getForm();
                $user = $form->getData();
                if (!$user instanceof User) {
                    return;
                }
                /** @var list<\App\Entity\Role> $chosen */
                $chosen = $form->get('roleEntities')->getData() ?? [];
                $current = $user->getRoleEntities();
                // Build a set of chosen Role IDs for O(1) membership.
                $chosenIds = [];
                foreach ($chosen as $r) {
                    $chosenIds[$r->getId()] = true;
                }
                foreach ($current as $existing) {
                    if (!isset($chosenIds[$existing->getId()])) {
                        $user->removeRole($existing);
                    }
                }
                foreach ($chosen as $role) {
                    if (!$current->contains($role)) {
                        $user->addRole($role);
                    }
                }
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
