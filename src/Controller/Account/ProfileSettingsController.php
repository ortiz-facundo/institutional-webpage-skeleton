<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\Administration\User;
use App\Form\Account\EditProfileFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Unified "Profile & Settings" page.
 *
 * Single URL that serves two purposes:
 *   1. Profile summary (avatar + name + email + account state + roles)
 *   2. Settings menu (grouped: Security / Preferences / Danger zone)
 *
 * Replaces the previous standalone /account/profile (read-only detail)
 * and /account/settings (sub-nav hub). The user's identification data and
 * the configuration menu now share one page so the chrome is consistent
 * and there is one place to land from the sidebar gear icon.
 *
 * Also builds the avatar-edit form (EditProfileController's POST target)
 * so the profile header's hover pencil/cross widget has a form to embed
 * — see account/_profile_header.html.twig and assets/js/avatar-editor.js.
 * No other settings page passes this variable, which is what scopes the
 * widget to this page only (the shared header partial checks whether
 * `avatarEditForm` is defined before rendering it).
 *
 * Section pages (today: Change password, Language) live under
 * /account/profile-settings/* and re-include the profile header so the
 * user sees their identity on every page of the experience.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProfileSettingsController extends AbstractController
{
    #[Route('/account/profile-settings', name: 'account_profile_settings', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('account/index.html.twig', [
            'avatarEditForm' => $this->createForm(EditProfileFormType::class, $user),
        ]);
    }
}
