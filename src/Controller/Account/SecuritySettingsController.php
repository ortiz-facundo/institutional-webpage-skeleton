<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Form\Account\ChangePasswordFormType;
use App\Service\Account\VerifyCurrentPassword;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service "Change password" section, the first leaf under the
 * "Security" group of the unified /account/profile-settings hub.
 *
 * Flow:
 *   - GET  → render the ChangePasswordFormType against the current user.
 *   - POST → on valid form (NotBlank + Length + repeated match):
 *       1. VerifyCurrentPassword against the user's stored hash. If
 *          wrong, attach a FormError to currentPassword and re-render
 *          the form (keeps the user's input, surfaces the error inline).
 *       2. Hash the new plaintext with UserPasswordHasherInterface,
 *          set it on the entity, flush.
 *       3. Audit log entry to the 'audit' channel.
 *       4. Flash 'success' and redirect back to the same URL (PRG
 *          pattern, so reload doesn't resubmit).
 *
 * No email is sent — changing one's own password does not warrant a
 * notification (the user just did it themselves).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SecuritySettingsController extends AbstractController
{
    public function __construct(
        private readonly VerifyCurrentPassword $verifyCurrent,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/account/profile-settings/change-password',
        name: 'app_account_profile_settings_change_password',
        methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $current */
            $current = (string) $form->get('currentPassword')->getData();
            /** @var string $new */
            $new = (string) $form->get('newPassword')->getData();

            if (!$this->verifyCurrent->verify($user, $current)) {
                $form->get('currentPassword')->addError(new FormError('Current password is incorrect.'));
                return $this->render('account/profile_settings/change_password.html.twig', ['form' => $form]);
            }

            $user->setPassword($this->hasher->hashPassword($user, $new));
            $this->em->flush();

            $this->auditLogger->info('user.password.changed', [
                'user_id'    => $user->getId(),
                'user_email' => $user->getEmail(),
                'ip'         => $request->getClientIp(),
            ]);

            $this->addFlash('success', 'Password updated.');
            return $this->redirectToRoute('app_account_profile_settings_change_password');
        }

        return $this->render('account/profile_settings/change_password.html.twig', ['form' => $form]);
    }
}