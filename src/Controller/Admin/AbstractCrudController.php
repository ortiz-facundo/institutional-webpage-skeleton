<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Security\Permission as Perm;

/**
 * Reusable CRUD controller. Subclasses provide a per-entity contract
 * (entity class, form type, templates, route prefix, required permission,
 * column definitions). The default index/new/edit/delete actions are
 * implemented here so concrete controllers stay tiny.
 *
 * Gating:
 *   - class-level #[IsGranted(getRequiredPermission())] is applied by the
 *     subclass; this class does not assume the permission so it can be
 *     reused by entities that need a different gate.
 *   - additional method-level checks (e.g. delete requires a stronger
 *     permission) can be added in subclasses by overriding.
 */
abstract class AbstractCrudController extends AbstractController
{
    public function __construct(protected readonly EntityManagerInterface $em)
    {
    }

    abstract protected function getEntityClass(): string;

    abstract protected function getFormType(): string;

    /** @return string template path, e.g. 'admin/role' */
    abstract protected function getTemplatePrefix(): string;

    /** @return string route prefix without trailing _index/_new/... e.g. 'admin_role' */
    abstract protected function getRoutePrefix(): string;

    /** @return list<array{label:string,path:string,class?:string}> */
    abstract protected function getListColumns(): array;

    abstract protected function getRequiredPermission(): string;

    abstract protected function getEntityLabel(): string;

    abstract protected function findEntity(int $id): ?object;

    abstract protected function newEntity(): object;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $repo = $this->em->getRepository($this->getEntityClass());
        $items = $repo->findBy([], ['id' => 'ASC']);

        return $this->render($this->getTemplatePrefix() . '/index.html.twig', [
            'items'         => $items,
            'columns'       => $this->getListColumns(),
            'title'         => $this->getEntityLabel() . 's',
            'new_path'      => $this->getRoutePrefix() . '_new',
            'new_label'     => 'New ' . $this->getEntityLabel(),
            'edit_path'     => $this->getRoutePrefix() . '_edit',
            'empty_message' => 'No ' . strtolower($this->getEntityLabel()) . 's yet. Create the first one to get started.',
            'trail'         => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => $this->getEntityLabel() . 's', 'path' => null],
            ],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->newEntity();
        $form = $this->createForm($this->getFormType(), $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($entity);
            $this->em->flush();
            $this->addFlash('success', $this->getEntityLabel() . ' created.');

            return $this->redirectToRoute($this->getRoutePrefix() . '_index');
        }

        return $this->render($this->getTemplatePrefix() . '/new.html.twig', [
            'form'         => $form,
            'title'        => 'New ' . $this->getEntityLabel(),
            'back_path'    => $this->getRoutePrefix() . '_index',
            'submit_label' => 'Create',
            'trail'        => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => $this->getEntityLabel() . 's', 'path' => $this->getRoutePrefix() . '_index'],
                ['label' => 'New', 'path' => null],
            ],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException('Entity not found.');
        }

        $form = $this->createForm($this->getFormType(), $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', $this->getEntityLabel() . ' updated.');

            return $this->redirectToRoute($this->getRoutePrefix() . '_index');
        }

        return $this->render($this->getTemplatePrefix() . '/edit.html.twig', [
            'form'         => $form,
            'entity'       => $entity,
            'title'        => 'Edit ' . $this->getEntityLabel(),
            'back_path'    => $this->getRoutePrefix() . '_index',
            'submit_label' => 'Save',
            'show_delete'  => true,
            'delete_path'  => $this->getRoutePrefix() . '_delete',
            'delete_token' => $this->container->get('security.csrf.token_manager')
                ->getToken('delete' . $id)->getValue(),
            'delete_label' => 'Delete ' . $this->getEntityLabel(),
            'trail'        => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => $this->getEntityLabel() . 's', 'path' => $this->getRoutePrefix() . '_index'],
                ['label' => 'Edit #' . $id, 'path' => null],
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $id, $token)) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException('Entity not found.');
        }

        $this->em->remove($entity);
        $this->em->flush();
        $this->addFlash('success', $this->getEntityLabel() . ' deleted.');

        return $this->redirectToRoute($this->getRoutePrefix() . '_index');
    }
}
