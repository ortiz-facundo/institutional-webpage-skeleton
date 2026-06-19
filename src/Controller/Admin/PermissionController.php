<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Permission;
use App\Repository\PermissionRepository;
use App\Security\Permission as Perm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/permissions', name: 'admin_permission_')]
#[IsGranted(Perm::PERMISSION_MANAGE_PERMISSIONS)]
final class PermissionController extends AbstractController
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $items = $this->permissions->findBy([], ['id' => 'ASC']);

        return $this->render('admin/permission/index.html.twig', [
            'items'  => $items,
            'title'  => 'Permissions',
            'trail'  => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => 'Permissions', 'path' => null],
            ],
        ]);
    }
}
