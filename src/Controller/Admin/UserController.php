<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserRolesType;
use App\Repository\UserRepository;
use App\Security\Permission as Perm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted(Perm::PERMISSION_MANAGE_USERS)]
    public function index(): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'items' => $this->users->findBy([], ['id' => 'ASC']),
            'title' => 'Users',
            'trail' => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => 'Users',     'path' => null],
            ],
        ]);
    }

    #[Route('/{id}/roles', name: 'edit_roles', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Perm::PERMISSION_MANAGE_USERS)]
    public function editRoles(int $id, Request $request): Response
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        $form = $this->createForm(UserRolesType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Roles updated.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit_roles.html.twig', [
            'form'         => $form,
            'user'         => $user,
            'title'        => 'Edit roles for ' . $user->getEmail(),
            'back_path'    => 'admin_user_index',
            'submit_label' => 'Save',
            'trail'        => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => 'Users',     'path' => 'admin_user_index'],
                ['label' => 'Edit roles', 'path' => null],
            ],
        ]);
    }
}
