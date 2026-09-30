<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Administration\User;
use App\Repository\Administration\UserRepository;
use App\Security\CsrfProtectedActionTrait;
use App\Security\Administration\UserActionVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin "log in as" / impersonation.
 *
 * Flow:
 *   1. Admin POSTs to /admin/users/{id}/impersonate with a CSRF token.
 *   2. UserActionVoter authorizes the target, then the controller writes
 *      an audit entry and redirects
 *      to /admin?_switch_user=<email>. Symfony's SwitchUserListener catches
 *      the redirect and swaps the token.
 *   3. Admin POSTs to /admin/exit-impersonation to come back.
 *
 * The firewall `switch_user` block (config/packages/security.yaml) gates the
 * actual mechanism by PERMISSION_IMPERSONATE_USERS — the redirect to
 * /admin?_switch_user=… would otherwise be open to anyone authenticated.
 * We add an extra defense-in-depth layer here (CSRF + admin-target guard)
 * and an audit trail.
 *
 * `exit-impersonation` has NO `#[IsGranted]` because the currently-active
 * token is the SwitchUserToken (the impersonated user), who does not hold
 * the impersonate permission. Gating instead on the token type lets the
 * exit work regardless of who is being impersonated.
 */
#[Route('/admin', name: 'administration_user_')]
final class ImpersonateController extends AbstractController
{
    use CsrfProtectedActionTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $auditLogger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/users/{id}/impersonate', name: 'impersonate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function impersonate(int $id, Request $request): Response
    {
        $this->assertValidCsrfToken($request, 'impersonate' . $id);

        $target = $this->users->find($id);
        if ($target === null) {
            throw $this->createNotFoundException(
                $this->translator->trans('app.administration.user.not_found')
            );
        }

        $this->denyAccessUnlessGranted(UserActionVoter::IMPERSONATE, $target);

        /** @var User|null $admin */
        $admin = $this->getUser();

        $this->auditLogger->info('impersonation.started', [
            'actor_id'     => $admin?->getId(),
            'actor_email'  => $admin?->getEmail(),
            'target_id'    => $target->getId(),
            'target_email' => $target->getEmail(),
            'ip'           => $request->getClientIp(),
        ]);

        // Symfony's SwitchUserListener reads ?_switch_user=<identifier> from
        // the NEXT request. We redirect through /dashboard so it lands on a
        // real backoffice route and gets a fresh audit trail entry on its own.
        return $this->redirect('/dashboard?_switch_user=' . urlencode($target->getUserIdentifier()));
    }

    #[Route('/exit-impersonation', name: 'exit_impersonation', methods: ['POST'])]
    public function exitImpersonation(Request $request): Response
    {
        // No IsGranted here — the currently-active token (SwitchUserToken
        // wrapping the original admin) is what should be allowed through.
        // The impersonated user (e.g. a non-admin) doesn't hold
        // PERMISSION_IMPERSONATE_USERS and would otherwise 403 the exit
        // and lock the admin into the impersonated session. We gate on
        // the SwitchUserToken presence instead.
        $current = $this->tokenStorage->getToken();
        if (!$current instanceof SwitchUserToken) {
            throw new AccessDeniedException(
                $this->translator->trans('app.administration.impersonation.not_currently_impersonating')
            );
        }

        $this->assertValidCsrfToken($request, 'exit_impersonation');

        // Capture who was being impersonated before we drop the SwitchUserToken.
        $impersonated = $current->getUser();
        $original = $current->getOriginalToken()->getUser();

        $this->auditLogger->info('impersonation.ended', [
            'actor_id'           => $original instanceof User ? $original->getId() : null,
            'actor_email'        => $original instanceof User ? $original->getEmail() : null,
            'impersonated_id'    => $impersonated instanceof User ? $impersonated->getId() : null,
            'impersonated_email' => $impersonated instanceof User ? $impersonated->getEmail() : null,
            'ip'                 => $request->getClientIp(),
        ]);

        return $this->redirect('/dashboard?_switch_user=' . SwitchUserListener::EXIT_VALUE);
    }
}
