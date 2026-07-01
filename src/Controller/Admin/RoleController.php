<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Role;
use App\Form\RoleType;
use App\Repository\RoleRepository;
use App\Security\Permission as Perm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/roles', name: 'admin_role_')]
#[IsGranted(Perm::PERMISSION_MANAGE_ROLES)]
final class RoleController extends AbstractCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly RoleRepository $roles,
    ) {
        parent::__construct($em);
    }

    protected function getEntityClass(): string
    {
        return Role::class;
    }

    protected function getFormType(): string
    {
        return RoleType::class;
    }

    protected function getTemplatePrefix(): string
    {
        return 'admin/role';
    }

    protected function getRoutePrefix(): string
    {
        return 'admin_role';
    }

    /** @return list<array{label:string,path:string,class?:string}> */
    protected function getListColumns(): array
    {
        return [
            ['label' => 'Name',     'path' => 'name',        'class' => 'font-mono text-xs'],
            ['label' => '# perms',  'path' => 'permissions', 'class' => 'text-stone-500'],
        ];
    }

    protected function getRequiredPermission(): string
    {
        return Perm::PERMISSION_MANAGE_ROLES;
    }

    protected function getEntityLabel(): string
    {
        return 'Role';
    }

    protected function getEntityDisplayName(object $entity): string
    {
        \assert($entity instanceof Role);
        return $entity->getName();
    }

    protected function findEntity(int $id): ?Role
    {
        return $this->roles->find($id);
    }

    protected function newEntity(): Role
    {
        return new Role('');
    }
}
