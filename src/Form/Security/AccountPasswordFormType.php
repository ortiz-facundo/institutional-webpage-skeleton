<?php

declare(strict_types=1);

namespace App\Form\Security;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Used by AccountConfirmationController to let the user pick a password
 * when they land on /account/confirm?selector=…&secret=….
 *
 * The data class is null — the form only carries the password field, and
 * the controller applies the value via the state-transition service (which
 * hashes and stores it). No entity is bound.
 *
 * All labels + constraint messages are translated through the
 * `validators` domain.
 */
final class AccountPasswordFormType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'first_options'   => [
                'label' => $this->translator->trans('app.administration.form.security.password.new'),
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'second_options'  => [
                'label' => $this->translator->trans('app.administration.form.security.password.repeat'),
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => $this->translator->trans('app.security.password.mismatch'),
            'mapped'          => false,
            'constraints'     => [
                new Assert\NotBlank(message: $this->translator->trans('app.security.password.required')),
                new Assert\Length(
                    min: 5,
                    max: 4096,
                    minMessage: $this->translator->trans('app.security.password.min', ['%limit%' => 5]),
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
