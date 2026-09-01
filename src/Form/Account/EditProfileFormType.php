<?php

declare(strict_types=1);

namespace App\Form\Account;

use App\Entity\Administration\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * The avatar-edit form embedded inline in the profile header's hover
 * pencil/cross widget (account/_profile_header.html.twig, only on
 * /account/profile-settings — see ProfileSettingsController). One field
 * — `avatarFile`, mapped straight onto the User entity via `data_class`
 * (unlike ChangePasswordFormType/LocaleType, which stay unmapped). Vich's
 * VichImageType is built around form<->entity property mapping to drive
 * its automatic upload/replace/delete handling, so mapping here — rather
 * than reimplementing that manually — is what actually gets the benefit
 * of the bundle.
 *
 * The template never renders VichImageType's own widget block — it pulls
 * `avatarFile.file` and `avatarFile.delete` as separate hidden sub-fields
 * driven entirely by JS (assets/js/avatar-editor.js), so Vich's own
 * preview/download/label rendering options are irrelevant here and
 * intentionally left unset.
 *
 * `allow_delete` still makes Vich add the `delete` checkbox sub-field
 * (only once the user has an existing photo) and handle its removal on
 * submit — the same single form/submit covers both "upload a new photo"
 * and "remove the current one".
 */
final class EditProfileFormType extends AbstractType
{
    private const int MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2 MB

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('avatarFile', VichImageType::class, [
            'required'        => false,
            'allow_delete'    => true,
            'constraints'     => [
                new Assert\Image(
                    maxSize: self::MAX_SIZE_BYTES,
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    // Defense in depth: the crop UI (assets/js/avatar-editor.js)
                    // always submits a square image, but a non-JS client could
                    // still post an arbitrary file directly to this route.
                    minRatio: 1,
                    maxRatio: 1,
                    maxSizeMessage: $this->translator->trans('app.account.edit_profile.photo.too_large', ['%limit%' => '2MB'], 'validators'),
                    mimeTypesMessage: $this->translator->trans('app.account.edit_profile.photo.invalid_type', domain: 'validators'),
                    minRatioMessage: $this->translator->trans('app.account.edit_profile.photo.invalid_ratio', domain: 'validators'),
                    maxRatioMessage: $this->translator->trans('app.account.edit_profile.photo.invalid_ratio', domain: 'validators'),
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => User::class,
            'csrf_token_id' => 'account_settings_edit_profile',
        ]);
    }
}
