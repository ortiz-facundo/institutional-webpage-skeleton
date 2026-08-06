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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/permissions', name: 'administration_permission_')]
#[IsGranted(Perm::PERMISSION_MANAGE_PERMISSIONS)]
final class PermissionController extends AbstractController
{
    public function __construct(
        private readonly PermissionRepository $permissions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $items = $this->permissions->findBy([], ['id' => 'ASC']);

        $byName = [];
        foreach ($items as $p) {
            $byName[$p->getName()] = $p;
        }

        $groups = [];
        foreach (Perm::groups() as $module => $names) {
            $group = [];
            foreach ($names as $name) {
                if (isset($byName[$name])) {
                    $group[] = $byName[$name];
                    unset($byName[$name]);
                }
            }
            $groups[$module] = $group;
        }
        if ($byName !== []) {
            $groups['other'] = array_values($byName);
        }

        return $this->render('administration/permission/list.html.twig', [
            'groups' => $groups,
            'total'  => count($items),
            'title'  => $this->translator->trans('app.administration.permission.list_title'),
            'trail'  => [
                ['label' => $this->translator->trans('app.administration.crud.breadcrumbs.dashboard'), 'path' => 'backoffice_dashboard'],
                ['label' => $this->translator->trans('app.administration.permission.list_title'), 'path' => null],
            ],
        ]);
    }
}
