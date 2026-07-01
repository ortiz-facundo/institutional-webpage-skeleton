<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Controller\AbstractCrudController;
use App\Entity\Administration\Role;
use App\Form\Administration\RoleType;
use App\Repository\Administration\RoleRepository;
use App\Security\Permission as Perm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/roles', name: 'administration_role_')]
#[IsGranted(Perm::PERMISSION_MANAGE_ROLES)]
final class RoleController extends AbstractCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        private readonly RoleRepository $roles,
    ) {
        parent::__construct($em, $translator);
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
        return 'administration/role';
    }

    protected function getRoutePrefix(): string
    {
        return 'administration_role';
    }

    /** @return list<array{label:string,path:string,class?:string}> */
    protected function getListColumns(): array
    {
        return [
            ['label' => $this->translator->trans('app.administration.columns.role.name'),       'path' => 'name',        'class' => 'font-mono text-xs'],
            ['label' => $this->translator->trans('app.administration.columns.role.perm_count'), 'path' => 'permissions', 'class' => 'text-stone-500'],
        ];
    }

    protected function getRequiredPermission(): string
    {
        return Perm::PERMISSION_MANAGE_ROLES;
    }

    protected function getEntityLabel(): string
    {
        return $this->translator->trans('app.administration.entity.role.label');
    }

    protected function getEntityLabelPlural(): string
    {
        return $this->translator->trans('app.administration.entity.role.label_plural');
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
