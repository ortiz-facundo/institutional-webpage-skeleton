<?php

declare(strict_types=1);

namespace App\Form\Security;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Used by AccountConfirmationController to let the user pick a password
 * when they land on /account/confirm?selector=…&secret=….
 *
 * The data class is null — the form only carries the password field, and
 * the controller applies the value via the state-transition service (which
 * hashes and stores it). No entity is bound.
 */
final class AccountPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'first_options'   => [
                'label' => 'Password',
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'second_options'  => [
                'label' => 'Repeat password',
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'Passwords do not match.',
            'mapped'          => false,
            'constraints'     => [
                new Assert\NotBlank(message: 'Pick a password.'),
                new Assert\Length(
                    min: 5,
                    max: 4096,
                    minMessage: 'Password must be at least {{ limit }} characters.',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'account_confirmation',
        ]);
    }
}