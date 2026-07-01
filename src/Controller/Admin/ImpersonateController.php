<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Permission as Perm;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin "log in as" / impersonation.
 *
 * Flow:
 *   1. Admin POSTs to /admin/users/{id}/impersonate with a CSRF token.
 *   2. Controller validates the token + permission, blocks self-impersonation
 *      and admin-to-admin impersonation, writes an audit entry, and redirects
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
#[Route('/admin', name: 'admin_')]
final class ImpersonateController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/users/{id}/impersonate', name: 'user_impersonate', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Perm::PERMISSION_IMPERSONATE_USERS)]
    public function impersonate(int $id, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('impersonate' . $id, $token)) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        /** @var User|null $admin */
        $admin = $this->getUser();
        $target = $this->users->find($id);
        if ($target === null) {
            throw $this->createNotFoundException('User not found.');
        }

        // Guard: self-impersonation is meaningless and confusing.
        if ($admin?->getId() === $target->getId()) {
            $this->addFlash('warning', 'You are already logged in as that user.');
            return $this->redirectToRoute('admin_user_index');
        }

        // Guard: only Active users can be impersonated. Pending users
        // have no password set; Disabled users are blocked by an admin.
        // Either way there is nothing useful to impersonate into.
        if (!$target->isActive()) {
            $this->auditLogger->warning('impersonation.blocked.target_inactive', [
                'actor_id'     => $admin?->getId(),
                'actor_email'  => $admin?->getEmail(),
                'target_id'    => $target->getId(),
                'target_email' => $target->getEmail(),
                'target_state' => $target->getState(),
            ]);
            $this->addFlash('error', sprintf(
                'Cannot impersonate %s — they are not Active (current state: %s).',
                $target->getEmail(),
                $target->getState(),
            ));
            return $this->redirectToRoute('admin_user_index');
        }

        // Guard: admins cannot impersonate other admins. The original admin
        // could already do everything the target admin can do; the only
        // effect of admin→admin impersonation is to muddy the audit trail.
        if ($this->targetIsAdmin($target)) {
            $this->auditLogger->warning('impersonation.blocked.target_is_admin', [
                'actor_id'     => $admin?->getId(),
                'actor_email'  => $admin?->getEmail(),
                'target_id'    => $target->getId(),
                'target_email' => $target->getEmail(),
            ]);
            $this->addFlash('error', 'Admins cannot impersonate other admins.');
            return $this->redirectToRoute('admin_user_index');
        }

        $this->auditLogger->info('impersonation.started', [
            'actor_id'     => $admin?->getId(),
            'actor_email'  => $admin?->getEmail(),
            'target_id'    => $target->getId(),
            'target_email' => $target->getEmail(),
            'ip'           => $request->getClientIp(),
        ]);

        // Symfony's SwitchUserListener reads ?_switch_user=<identifier> from
        // the NEXT request. We redirect through /admin so it lands on a real
        // admin route and gets a fresh audit trail entry on its own.
        return $this->redirect('/admin?_switch_user=' . urlencode($target->getUserIdentifier()));
    }

    #[Route('/exit-impersonation', name: 'user_exit_impersonation', methods: ['POST'])]
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
            throw new AccessDeniedException('Not currently impersonating anyone.');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('exit_impersonation', $token)) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

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

        return $this->redirect('/admin?_switch_user=' . SwitchUserListener::EXIT_VALUE);
    }

    /**
     * A user is considered "an admin" if any of their roles grants at
     * least one PERMISSION_MANAGE_* permission. We use this to deny
     * admin-to-admin impersonation — there's no legitimate support reason
     * to swap between two admins; the audit trail becomes unreadable.
     */
    private function targetIsAdmin(User $target): bool
    {
        foreach ($target->getRoleEntities() as $role) {
            foreach ($role->getPermissions() as $perm) {
                if (str_starts_with($perm->getName(), 'PERMISSION_MANAGE_')) {
                    return true;
                }
            }
        }
        return false;
    }
}