<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

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
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'       => 'Current password',
                'attr'        => ['autocomplete' => 'current-password'],
                'mapped'      => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter your current password.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => [
                    'label' => 'New password',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => 'Repeat new password',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Passwords do not match.',
                'mapped'          => false,
                'constraints'     => [
                    new Assert\NotBlank(message: 'Pick a new password.'),
                    new Assert\Length(
                        min: 5,
                        max: 4096,
                        minMessage: 'Password must be at least {{ limit }} characters.',
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