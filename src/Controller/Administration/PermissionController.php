<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Administration\Permission;
use App\Repository\Administration\PermissionRepository;
use App\Security\Permission as Perm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/permissions', name: 'administration_permission_')]
#[IsGranted(Perm::PERMISSION_MANAGE_PERMISSIONS)]
final class PermissionController extends AbstractController
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $items = $this->permissions->findBy([], ['id' => 'ASC']);

        return $this->render('administration/permission/list.html.twig', [
            'items'  => $items,
            'title'  => 'Permissions',
            'trail'  => [
                ['label' => 'Dashboard', 'path' => 'backoffice_dashboard'],
                ['label' => 'Permissions', 'path' => null],
            ],
        ]);
    }
}
