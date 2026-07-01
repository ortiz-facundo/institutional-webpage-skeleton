<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\Administration\User;
use App\Form\Security\AccountPasswordFormType;
use App\Service\Security\UserLifecycle\AccountTokenValidator;
use App\Service\Security\UserLifecycle\ChangeUserStateToActive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public route used by the link emailed to a freshly-created user.
 *
 * Flow:
 *   - GET /account/confirm?selector=…&secret=… → renders the
 *     AccountPasswordFormType against the user that owns the token.
 *   - POST same URL → on a valid token + valid password, calls
 *     ChangeUserStateToActive (which hashes the password, sets state to
 *     USER_STATE_ACTIVE, audits), consumes the token, redirects to
 *     /login with a success flash.
 *
 * Re-checking the token on POST (instead of trusting GET state) defends
 * against the form being POSTed long after the GET was rendered.
 */
final class AccountConfirmationController extends AbstractController
{
    public function __construct(
        private readonly AccountTokenValidator $validator,
        private readonly ChangeUserStateToActive $toActive,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/confirm', name: 'security_account_confirm', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $selector = (string) $request->query->get('selector', '');
        $secret   = (string) $request->query->get('secret', '');

        $token = $selector !== '' && $secret !== ''
            ? $this->validator->findValid($selector, $secret)
            : null;

        if ($token === null) {
            return $this->render('security/confirm_invalid.html.twig');
        }

        $user = $token->getUser();

        // If the user is no longer Pending (admin disabled them between
        // the email being sent and the user clicking the link), the token
        // is dead.
        if (!$user->isPending()) {
            return $this->render('security/confirm_invalid.html.twig', [
                'reason' => 'user_no_longer_pending',
            ]);
        }

        $form = $this->createForm(AccountPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $plain */
            $plain = $form->get('plainPassword')->getData();

            try {
                $this->toActive->execute($user, (string) $plain);
                $this->validator->consume($token);
                $this->em->flush();
            } catch (\DomainException $e) {
                // Re-validate failed (e.g., admin disabled the user in
                // the millisecond between GET and POST). Surface a clear
                // error instead of a 500.
                $this->addFlash('error', $this->translator->trans('confirm.activation_failed'));
                return $this->render('security/confirm_invalid.html.twig');
            }

            $this->addFlash('success', $this->translator->trans('confirm.success'));
            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/confirm.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}