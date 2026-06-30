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

    /**
     * Human-readable label for an entity, used in the edit/view page
     * titles and breadcrumbs. Concrete controllers return the most
     * identifying string the entity has (e.g. User → name ?? email;
     * Role → name).
     */
    abstract protected function getEntityDisplayName(object $entity): string;

    abstract protected function findEntity(int $id): ?object;

    abstract protected function newEntity(): object;

    /**
     * Hook for subclasses to mutate the entity after the form binds and
     * before it is persisted (create) or flushed (edit). Default is a no-op.
     *
     * Common uses: hash a plaintext password, normalize a slug, copy a
     * field from the form to a derived value on the entity, etc.
     *
     * @param bool $isCreate true on `new()`, false on `edit()`
     */
    protected function onFormSubmit(object $entity, FormInterface $form, bool $isCreate): void
    {
    }

    /**
     * Hook called after a successful create (after `em->persist` +
     * `em->flush`). Use this for side-effects that require a database
     * id — token rotation, file uploads, audit-log entries that record
     * the new entity id, etc. Default: no-op.
     */
    protected function onCreateSuccess(object $entity): void
    {
    }

    /**
     * Route name for an "impersonate this row" action, or null to disable
     * the button. Default: null. Subclasses that want a "log in as" button
     * on each row override this to return the route name. The caller is
     * responsible for ensuring the current user is allowed to impersonate
     * (e.g. by returning null when `is_granted(PERMISSION_IMPERSONATE_USERS)`
     * is false); the actual route still has `#[IsGranted]` as defence in
     * depth.
     */
    protected function getImpersonatePath(): ?string
    {
        return null;
    }

    /**
     * Per-row hide flag for the impersonate button. Default: never hide.
     * Subclasses override to suppress the button on the current user's own
     * row, on admins, etc. The list partial uses the inverse — "show if
     * not hidden" — so the default behaviour is "show on every row".
     */
    protected function shouldHideImpersonate(object $entity): bool
    {
        return false;
    }

    /**
     * Route name for a per-row "resend" action (e.g. resending the
     * account confirmation email for a Pending user). Default: null →
     * no resend button rendered.
     */
    protected function getResendConfirmationPath(): ?string
    {
        return null;
    }

    /**
     * Per-row hide flag for the resend button. Default: never hide.
     * Subclasses override to suppress on rows that aren't eligible
     * (e.g. non-Pending users for the confirmation-resend flow).
     */
    protected function shouldHideResend(object $entity): bool
    {
        return false;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $repo = $this->em->getRepository($this->getEntityClass());
        $items = $repo->findBy([], ['id' => 'ASC']);

        $csrf = $this->container->get('security.csrf.token_manager');

        // Pre-generate a per-row delete CSRF token (the token id is
        // `delete{id}`, scoped to the entity, so we need one per row).
        $deleteTokens = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $deleteTokens[$id] = $csrf->getToken('delete' . $id)->getValue();
            }
        }

        // Impersonate button: optional. Only generates tokens for rows where
        // the button is actually shown (avoids producing tokens for hidden
        // rows). The set of hidden IDs is also passed so the partial can
        // skip rendering the form per row.
        $impersonatePath = $this->getImpersonatePath();
        $impersonateTokens = [];
        $hiddenIds = [];
        if ($impersonatePath !== null) {
            foreach ($items as $item) {
                $id = $item->getId();
                if ($id === null) {
                    continue;
                }
                if ($this->shouldHideImpersonate($item)) {
                    $hiddenIds[$id] = true;
                    continue;
                }
                $impersonateTokens[$id] = $csrf->getToken('impersonate' . $id)->getValue();
            }
        }

        // Resend button: optional. Same per-row token pattern.
        $resendPath = $this->getResendConfirmationPath();
        $resendTokens = [];
        $resendHidden = [];
        if ($resendPath !== null) {
            foreach ($items as $item) {
                $id = $item->getId();
                if ($id === null) {
                    continue;
                }
                if ($this->shouldHideResend($item)) {
                    $resendHidden[$id] = true;
                    continue;
                }
                $resendTokens[$id] = $csrf->getToken('resend_confirmation' . $id)->getValue();
            }
        }

        return $this->render($this->getTemplatePrefix() . '/index.html.twig', [
            'items'              => $items,
            'columns'            => $this->getListColumns(),
            'title'              => $this->getEntityLabel() . 's',
            'entity_label'       => $this->getEntityLabel(),
            'new_path'           => $this->getRoutePrefix() . '_new',
            'new_label'          => 'New ' . $this->getEntityLabel(),
            'view_path'          => $this->getRoutePrefix() . '_view',
            'edit_path'          => $this->getRoutePrefix() . '_edit',
            'delete_path'        => $this->getRoutePrefix() . '_delete',
            'delete_tokens'      => $deleteTokens,
            'impersonate_path'   => $impersonatePath,
            'impersonate_tokens' => $impersonateTokens,
            'hide_impersonate'   => array_keys($hiddenIds),
            'resend_path'        => $resendPath,
            'resend_tokens'      => $resendTokens,
            'hide_resend'        => array_keys($resendHidden),
            'empty_message'      => 'No ' . strtolower($this->getEntityLabel()) . 's yet. Create the first one to get started.',
            'trail'              => [
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
            $this->onFormSubmit($entity, $form, true);
            $this->em->persist($entity);
            $this->em->flush();
            $this->onCreateSuccess($entity);
            $this->addFlash('success', $this->getEntityLabel() . ' created.');

            return $this->redirectToRoute($this->getRoutePrefix() . '_index');
        }

        return $this->render($this->getTemplatePrefix() . '/new.html.twig', [
            'form'         => $form,
            'entity'       => $entity,
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
            $this->onFormSubmit($entity, $form, false);
            $this->em->flush();
            $this->addFlash('success', $this->getEntityLabel() . ' updated.');

            return $this->redirectToRoute($this->getRoutePrefix() . '_index');
        }

        return $this->render($this->getTemplatePrefix() . '/edit.html.twig', [
            'form'         => $form,
            'entity'       => $entity,
            'title'        => 'Edit ' . $this->getEntityDisplayName($entity),
            'back_path'    => $this->getRoutePrefix() . '_index',
            'submit_label' => 'Save',
            'trail'        => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => $this->getEntityLabel() . 's', 'path' => $this->getRoutePrefix() . '_index'],
                ['label' => 'Edit ' . $this->getEntityDisplayName($entity), 'path' => null],
            ],
        ]);
    }

    #[Route('/{id}/view', name: 'view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException('Entity not found.');
        }

        return $this->render($this->getTemplatePrefix() . '/view.html.twig', [
            'entity'       => $entity,
            'title'        => $this->getEntityDisplayName($entity),
            'entity_label' => $this->getEntityLabel(),
            'back_path'    => $this->getRoutePrefix() . '_index',
            'edit_path'    => $this->getRoutePrefix() . '_edit',
            'trail'        => [
                ['label' => 'Dashboard', 'path' => 'admin_dashboard'],
                ['label' => $this->getEntityLabel() . 's', 'path' => $this->getRoutePrefix() . '_index'],
                ['label' => $this->getEntityDisplayName($entity), 'path' => null],
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
