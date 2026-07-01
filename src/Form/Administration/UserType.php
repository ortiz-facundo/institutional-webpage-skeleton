<?php

declare(strict_types=1);

namespace App\Form\Administration;

use App\Entity\Administration\Role;
use App\Entity\Administration\User;
use App\Repository\Administration\RoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Backoffice User form.
 *
 * Notes:
 *  - No password field. Admins cannot set or change other users'
 *    passwords — passwords are owned by the user themselves, set via the
 *    /account/confirm flow. The User entity keeps `setPassword()` for
 *    service use (ChangeUserStateToActive) but the form does not.
 *  - State defaults to USER_STATE_PENDING on create (the User entity's
 *    own default). The `state` field is hidden on the create form via
 *    the `show_state` option (default: true), so admins cannot pick a
 *    different starting state from the UI — the only way to leave
 *    Pending on create is the deliberate user-lifecycle flow. On edit,
 *    all three values are selectable; the state-change side effects
 *    (audit, email, token rotation) are handled in the controller via
 *    UserStateTransitionService.
 *  - roleEntities is `mapped: false` and diffed in POST_SUBMIT to
 *    sidestep the Doctrine+EntityType data-mapper bug (see the form
 *    commit history for the long explanation).
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
        ;

        if ($options['show_state']) {
            $builder->add('state', ChoiceType::class, [
                'choices' => [
                    'Pending'  => User::USER_STATE_PENDING,
                    'Active'   => User::USER_STATE_ACTIVE,
                    'Disabled' => User::USER_STATE_DISABLED,
                ],
                // `mapped: false` so the form's data mapper does NOT touch
                // User::$state directly. State transitions must go through
                // the ChangeUserState* services (for validations + audit +
                // side-effects), and the controller's onFormSubmit reads
                // `$form->get('state')->getData()` to figure out the new
                // state and dispatches via UserStateTransitionService.
                'mapped'      => false,
                'label'       => 'Account state',
                'expanded'    => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Choice([
                        'choices' => User::USER_STATES,
                        'message' => 'Invalid state.',
                    ]),
                ],
                'help'        => 'Pending: created, awaiting confirmation. Active: password set, can log in. Disabled: blocked by an admin.',
            ]);
        }

        $builder->add('roleEntities', EntityType::class, [
            'class'        => Role::class,
            'choice_label' => 'name',
            'choices'      => $this->roles->findBy([], ['name' => 'ASC']),
            'multiple'     => true,
            'expanded'     => true,
            'mapped'       => false,
            'constraints'  => [new Assert\Count(min: 1, minMessage: 'Assign at least one role.')],
            'label'        => 'Roles',
        ]);

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
                /** @var list<Role> $chosen */
                $chosen = $form->get('roleEntities')->getData() ?? [];
                $current = $user->getRoleEntities();
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
            // Whether to render the Account-state field. The create flow
            // hides it (the entity's own default of USER_STATE_PENDING is
            // what gets persisted); the edit flow shows it.
            'show_state' => true,
        ]);
    }
}