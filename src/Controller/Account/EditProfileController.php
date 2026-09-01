<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\Administration\User;
use App\Form\Account\EditProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Handles the profile-photo form embedded inline in the avatar on
 * /account/profile-settings (see the hover pencil/cross widget in
 * account/_profile_header.html.twig, driven by assets/js/avatar-editor.js).
 *
 * POST-only: there is no page of its own anymore — a crop-confirm or a
 * delete-confirm both auto-submit the same form via `requestSubmit()`.
 * The form is entity-mapped (data_class = User), so VichImageType does
 * the actual upload/replace/delete handling during $em->flush().
 *
 * On success, PRG back to the hub (where the widget lives). On
 * validation failure, re-render the hub itself with the invalid form so
 * ProfileSettingsController's own template can surface the errors —
 * mirrors the PRG shape of SecuritySettingsController/LocaleController,
 * just landing on a shared page instead of a dedicated one.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EditProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/profile-settings/edit-profile',
        name: 'account_profile_settings_edit_profile',
        methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(EditProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $avatarField = $form->get('avatarFile');
            $wasDeleteRequested = $avatarField->has('delete') && $avatarField->get('delete')->getData() === true;

            $this->em->flush();

            if ($wasDeleteRequested) {
                $this->auditLogger->info('user.avatar.removed', [
                    'user_id'    => $user->getId(),
                    'user_email' => $user->getEmail(),
                    'ip'         => $request->getClientIp(),
                ]);
                $this->addFlash('success', $this->translator->trans('app.account.edit_profile.flash.removed'));
            } else {
                $this->auditLogger->info('user.avatar.updated', [
                    'user_id'    => $user->getId(),
                    'user_email' => $user->getEmail(),
                    'ip'         => $request->getClientIp(),
                ]);
                $this->addFlash('success', $this->translator->trans('app.account.edit_profile.flash.updated'));
            }

            return $this->redirectToRoute('account_profile_settings');
        }

        return $this->render('account/index.html.twig', [
            'avatarEditForm' => $form,
        ]);
    }
}
