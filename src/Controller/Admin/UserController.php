<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\Permission as Perm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted(Perm::PERMISSION_MANAGE_USERS)]
final class UserController extends AbstractCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
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

    /** @return list<array{label:string,path:string,class?:string}> */
    protected function getListColumns(): array
    {
        return [
            ['label' => '#',    'path' => 'id'],
            ['label' => 'Email', 'path' => 'email'],
            ['label' => 'Name',  'path' => 'name'],
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
     * Hash the plaintext password on submit, when present. On create the
     * form enforces NotBlank so the field is non-empty. On edit, an empty
     * field leaves the existing hash untouched.
     */
    protected function onFormSubmit(object $entity, FormInterface $form, bool $isCreate): void
    {
        if (!$entity instanceof User) {
            return;
        }
        $plainPassword = $form->get('plainPassword')->getData();
        if ($plainPassword) {
            $entity->setPassword($this->hasher->hashPassword($entity, $plainPassword));
        }
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
     *     admin-to-admin impersonation is denied by policy.
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
        return false;
    }
}
