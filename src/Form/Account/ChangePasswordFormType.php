<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Self-service "change password" form used by SecuritySettingsController.
 *
 * Three fields, all unmapped:
 *   - currentPassword: the user's existing password, verified by
 *     VerifyCurrentPassword on the controller side (after form validation).
 *   - newPassword: a repeated pair for typo protection. Length policy
 *     mirrors AccountPasswordFormType (min 5) — see that file's docblock
 *     for the rationale (defence in depth at the service layer too).
 *
 * No data_class — the controller applies the new hash directly via
 * UserPasswordHasherInterface after the form is valid AND the current
 * password has been verified, mirroring AccountPasswordFormType's shape.
 *
 * All labels + constraint messages are translated through the
 * `validators` domain (see translations/administration/validators.<locale>.yaml).
 */
final class ChangePasswordFormType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'       => $this->translator->trans('app.administration.form.account.password.current'),
                'attr'        => ['autocomplete' => 'current-password'],
                'mapped'      => false,
                'constraints' => [
                    new Assert\NotBlank(message: $this->translator->trans('app.account.password.current_required', domain: 'validators')),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => [
                    'label' => $this->translator->trans('app.administration.form.account.password.new'),
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => $this->translator->trans('app.administration.form.account.password.repeat'),
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => $this->translator->trans('app.account.password.mismatch', domain: 'validators'),
                'mapped'          => false,
                'constraints'     => [
                    new Assert\NotBlank(message: $this->translator->trans('app.account.password.new_required', domain: 'validators')),
                    new Assert\Length(
                        min: 5,
                        max: 4096,
                        minMessage: $this->translator->trans('app.account.password.min', ['%limit%' => 5], 'validators'),
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'account_settings_change_password',
        ]);
    }
}
