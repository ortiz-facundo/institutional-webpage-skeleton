<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\Permission as Perm;
use App\Service\UserLifecycle\UserStateTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted(Perm::PERMISSION_MANAGE_USERS)]
final class UserController extends AbstractCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserStateTransitionService $stateTransition,
        private readonly Security $security,
    ) {
        parent::__construct($em);
    }

    protected function getEntityClass(): string
    {
        return User::class;
    }

    protected function getFormType(): string
    {
        return UserType::class;
    }

    protected function getTemplatePrefix(): string
    {
        return 'admin/user';
    }

    protected function getRoutePrefix(): string
    {
        return 'admin_user';
    }

    /** @return list<array{label:string,path:string,class?:string,cell_partial?:string}> */
    protected function getListColumns(): array
    {
        return [
            ['label' => 'Email', 'path' => 'email'],
            ['label' => 'Name',  'path' => 'name'],
            ['label' => 'State', 'path' => 'state', 'cell_partial' => '_state_pill.html.twig'],
        ];
    }

    protected function getRequiredPermission(): string
    {
        return Perm::PERMISSION_MANAGE_USERS;
    }

    protected function getEntityLabel(): string
    {
        return 'User';
    }

    protected function getEntityDisplayName(object $entity): string
    {
        \assert($entity instanceof User);
        return $entity->getName() ?? $entity->getEmail();
    }

    protected function findEntity(int $id): ?User
    {
        return $this->users->find($id);
    }

    protected function newEntity(): User
    {
        return new User();
    }

    /**
     * The Account-state field is hidden on the create form. Newly
     * created users always start in USER_STATE_PENDING (the entity's
     * own default), and the user-lifecycle flow moves them to Active
     * once they confirm and set a password. Edit keeps the field.
     *
     * @return array<string, mixed>
     */
    protected function getFormOptions(bool $isCreate): array
    {
        return ['show_state' => !$isCreate];
    }

    /**
     * Called by AbstractCrudController::edit() before flush. If the
     * state changed we go through the lifecycle service (so audit,
     * email-notifier, token invalidation, etc. fire). Email changes
     * for Pending users trigger a resend of the confirmation link to
     * the new address.
     *
     * @param bool $isCreate true on `new()`, false on `edit()`
     */
    protected function onFormSubmit(object $entity, FormInterface $form, bool $isCreate): void
    {
        if (!$entity instanceof User) {
            return;
        }

        if ($isCreate) {
            // Newly created user starts in Pending (the entity's
            // own default; the form hides the Account-state field on
            // create, so there is no way to start in a different state
            // from the UI). State transitions on edit go through the
            // lifecycle service below.
            return;
        }

        // The state field is unmapped (see UserType), so the form's data
        // mapper doesn't touch User::$state. We read the new value from
        // the form here and dispatch through the lifecycle service so
        // audit + side-effects fire. The entity's state field still
        // holds the old value at this point (refreshed by Doctrine on
        // em->flush after this method returns).
        $newState = (string) $form->get('state')->getData();
        if ($entity->getState() !== $newState) {
            $this->stateTransition->transitionTo($entity, $newState);
        }
    }

    /**
     * Called by AbstractCrudController::new() after persist+flush.
     * Issues a confirmation token and emails the link to the user
     * (new accounts always start in Pending now that the create
     * form hides the Account-state field).
     */
    protected function onCreateSuccess(object $entity): void
    {
        if (!$entity instanceof User) {
            return;
        }
        if ($entity->isPending()) {
            $this->stateTransition->resendConfirmation($entity);
        }
    }

    /**
     * Replace the generic "User created." flash with one that names the
     * recipient, so the admin can see at a glance that the confirmation
     * email went out.
     */
    protected function getCreatedFlashMessage(object $entity): ?string
    {
        if (!$entity instanceof User) {
            return parent::getCreatedFlashMessage($entity);
        }
        if ($entity->isPending()) {
            return sprintf(
                'User created. Confirmation email sent to %s.',
                $entity->getEmail()
            );
        }
        return parent::getCreatedFlashMessage($entity);
    }

    /**
     * POST /admin/users/{id}/resend-confirmation — only visible for
     * Pending users in the list. CSRF-protected, gated by
     * PERMISSION_MANAGE_USERS via the class-level attribute.
     */
    #[Route('/{id}/resend-confirmation', name: 'resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendConfirmation(int $id, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('resend_confirmation' . $id, $token)) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        $this->stateTransition->resendConfirmation($user);
        $this->addFlash('success', sprintf('Confirmation email re-sent to %s.', $user->getEmail()));
        return $this->redirectToRoute('admin_user_index');
    }

    /**
     * Enable the per-row "log in as" button only for callers who actually
     * hold the impersonate permission. The route itself is gated again by
     * `#[IsGranted]` on ImpersonateController::impersonate().
     */
    protected function getImpersonatePath(): ?string
    {
        if (!$this->security->isGranted(Perm::PERMISSION_IMPERSONATE_USERS)) {
            return null;
        }
        return 'admin_user_impersonate';
    }

    /**
     * Hide the impersonate button on:
     *   - the current admin's own row (self-impersonation is blocked at
     *     the controller too, but hiding it in the UI is clearer);
     *   - any user that already holds a PERMISSION_MANAGE_* permission —
     *     admin-to-admin impersonation is denied by policy;
     *   - users that are not Active — Pending users have no password
     *     set and Disabled users are blocked by an admin; either way
     *     there is nothing useful to impersonate into.
     */
    protected function shouldHideImpersonate(object $entity): bool
    {
        \assert($entity instanceof User);

        $current = $this->security->getUser();
        if ($current instanceof User && $current->getId() === $entity->getId()) {
            return true;
        }

        foreach ($entity->getRoleEntities() as $role) {
            foreach ($role->getPermissions() as $perm) {
                if (str_starts_with($perm->getName(), 'PERMISSION_MANAGE_')) {
                    return true;
                }
            }
        }

        // Only Active users are valid impersonation targets.
        if (!$entity->isActive()) {
            return true;
        }

        return false;
    }

    /**
     * Per-row resend button on the user list. Visible only for Pending
     * users (the only state where a confirmation token makes sense).
     */
    protected function getResendConfirmationPath(): ?string
    {
        return 'admin_user_resend';
    }

    // (route name is built via the class-level + method-level prefix: admin_user_resend)
    // Symfony combines them, so the public name is admin_user_resend.

    protected function shouldHideResend(object $entity): bool
    {
        \assert($entity instanceof User);
        // Only show resend for Pending users; Active and Disabled users
        // already have a usable credential or are blocked by admin.
        return !$entity->isPending();
    }
}