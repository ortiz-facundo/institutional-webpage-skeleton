<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\Administration\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The /account/profile-settings/locale form. One field — a ChoiceType
 * restricted to the locales listed in User::SUPPORTED_LOCALES.
 *
 * The form values are the bare locale codes ('en' / 'es'). The
 * `choice_label` closure translates each into its own language so a
 * native English speaker sees "English" and a native Spanish speaker
 * sees "Español" — independent of the current UI locale.
 *
 * No data_class — the controller reads $form->get('locale')->getData()
 * and applies it to the User via User::setLocale() (which validates
 * against the same SUPPORTED_LOCALES set).
 */
final class LocaleType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $translator = $this->translator;
        $builder->add('locale', ChoiceType::class, [
            'label'       => $translator->trans('app.account.locale.label'),
            // The values Symfony submits ('en' / 'es'). The
            // `choice_label` closure turns each into a translated label
            // resolved in the value's OWN language.
            'choices'     => User::SUPPORTED_LOCALES,
            'choice_label' => static fn (string $value): string => $translator->trans(
                'app.account.locale.options.' . $value,
                [],
                null,
                $value
            ),
            'expanded'   => false,
            'multiple'   => false,
            'data'       => $options['current_locale'] ?? 'es',
            'constraints' => [
                new Assert\NotBlank(message: $translator->trans('app.shared.validators.required')),
                new Assert\Choice([
                    'choices' => User::SUPPORTED_LOCALES,
                    'message' => $translator->trans('app.shared.validators.invalid_choice'),
                ]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'current_locale' => 'es',
            // Required so the option resolver accepts the extra arg
            // (the controller passes the User's existing preference).
            'current_locale_required' => false,
        ]);
    }
}
