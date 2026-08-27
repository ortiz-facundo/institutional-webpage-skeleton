<?php

declare(strict_types=1);

namespace App\Form\Administration;

use App\Entity\Administration\Role;
use App\Entity\Administration\User;
use App\Repository\Administration\RoleRepository;
use App\Validator\Constraints\OnlyAdminCanGrantAdminRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

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
 *  - roleEntities is `mapped: false`. Two non-event patterns bridge the
 *    entity and the form:
 *      * preBindForm() (controller hook) populates it from the entity's
 *        current roles before the request is bound.
 *      * onFormSubmit() (controller hook) diffs the chosen roles back
 *        to the entity after a successful submit.
 *    This replaces the old POST_SET_DATA / POST_SUBMIT event listeners
 *    — the policy is now enforced declaratively via the
 *    OnlyAdminCanGrantAdminRole Constraint attached below, which runs
 *    during $form->isValid() and rejects the submission with an inline
 *    error on any disallowed checkbox.
 *
 *  All visible labels, helps, and constraint messages are translated
 *  through the `messages` / `validators` domains. See
 *  translations/administration/messages.<locale>.yaml and
 *  translations/administration/validators.<locale>.yaml.
 */
class UserType extends AbstractType
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => $this->translator->trans('app.administration.form.user.email'),
                'constraints' => [
                    new Assert\NotBlank(message: $this->translator->trans('app.shared.validators.required')),
                    new Assert\Email(),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => $this->translator->trans('app.administration.form.user.name'),
                'required' => false,
                'constraints' => [new Assert\Length(max: 120)],
            ])
        ;

        if ($options['show_state']) {
            $builder->add('state', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('app.shared.state.USER_STATE_PENDING')  => User::USER_STATE_PENDING,
                    $this->translator->trans('app.shared.state.USER_STATE_ACTIVE')   => User::USER_STATE_ACTIVE,
                    $this->translator->trans('app.shared.state.USER_STATE_DISABLED') => User::USER_STATE_DISABLED,
                ],
                // `mapped: false` so the form's data mapper does NOT touch
                // User::$state directly. State transitions must go through
                // the ChangeUserState* services (for validations + audit +
                // side-effects), and the controller's onFormSubmit reads
                // `$form->get('state')->getData()` to figure out the new
                // state and dispatches via UserStateTransitionService.
                'mapped'      => false,
                'label'       => $this->translator->trans('app.administration.form.user.state'),
                'expanded'    => false,
                'constraints' => [
                    new Assert\NotBlank(message: $this->translator->trans('app.shared.validators.required')),
                    new Assert\Choice([
                        'choices' => User::USER_STATES,
                        'message' => $this->translator->trans('app.user.state.invalid'),
                    ]),
                ],
                'help'        => $this->translator->trans('app.administration.user.state_help'),
            ]);
        }

        $builder->add('roleEntities', EntityType::class, [
            'class'        => Role::class,
            'choice_label' => 'name',
            'choices'      => $this->roles->findBy([], ['name' => 'ASC']),
            'multiple'     => true,
            'expanded'     => true,
            'mapped'       => false,
            'constraints'  => [
                new Assert\Count(min: 1, minMessage: $this->translator->trans('app.user.role.min')),
                new OnlyAdminCanGrantAdminRole(),
            ],
            'label'        => $this->translator->trans('app.administration.form.user.roles'),
        ]);
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
