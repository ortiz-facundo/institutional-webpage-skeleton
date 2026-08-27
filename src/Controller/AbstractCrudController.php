<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\CsrfProtectedActionTrait;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
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
 *
 * All user-facing strings produced by this base are translated via
 * TranslatorInterface. Entity labels are still produced by the concrete
 * controller (so the "User" / "Role" constants stay machine-only) and
 * are interpolated into the `app.administration.crud.*` templates
 * (titles, flash, empty, breadcrumbs, …).
 */
abstract class AbstractCrudController extends AbstractController
{
    use CsrfProtectedActionTrait;

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly TranslatorInterface $translator,
    ) {
    }

    abstract protected function getEntityClass(): string;

    abstract protected function getFormType(): string;

    /** @return string template path, e.g. 'administration/role' */
    abstract protected function getTemplatePrefix(): string;

    /** @return string route prefix without trailing _list/_new/... e.g. 'administration_role' */
    abstract protected function getRoutePrefix(): string;

    /** @return list<array{label:string,path:string,class?:string}> */
    abstract protected function getListColumns(): array;

    abstract protected function getRequiredPermission(): string;

    abstract protected function getEntityLabel(): string;

    /**
     * Pluralised entity label. Used for list-page titles, empty-state
     * copy, breadcrumbs, etc. — anything where the entity is referred
     * to as a collection. Concrete controllers return the translated
     * plural (e.g. "Users" / "Usuarios").
     */
    abstract protected function getEntityLabelPlural(): string;

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
     * Per-context options passed to `createForm()`. Default is empty;
     * subclasses override to inject form options that depend on whether
     * we're rendering the create form vs the edit form (e.g. hiding a
     * field on create). The third arg of `createForm()` is forwarded
     * straight to the form type's `configureOptions()`.
     *
     * @return array<string, mixed>
     */
    protected function getFormOptions(bool $isCreate): array
    {
        return [];
    }

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
     * Hook called after the form is created and bound to the entity,
     * BEFORE `$form->handleRequest($request)`. Subclasses use this to
     * pre-populate `mapped: false` fields from the entity (the
     * non-event replacement for the legacy `POST_SET_DATA` listener
     * pattern). Default is a no-op.
     */
    protected function preBindForm(FormInterface $form, object $entity): void
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
     * Success-flash message shown after a successful create. Default:
     * "%entity% created." (trans key app.administration.flash.created).
     * Subclasses can override to add context (e.g. the recipient of a
     * confirmation email). Return null to suppress the generic flash
     * entirely — useful when `onCreateSuccess` already added its own
     * success flash and you don't want both to show up.
     */
    protected function getCreatedFlashMessage(object $entity): ?string
    {
        return $this->translator->trans('app.administration.flash.created', [
            '%entity%' => $this->getEntityLabel(),
        ]);
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

    /**
     * Hook for subclasses to block deletion of an entity that still has
     * dependent records the deletion would silently affect (e.g. a Role
     * still assigned to Users — the `user_role` FK is ON DELETE CASCADE,
     * so without this check deleting the Role would silently strip it
     * from every user who had it, with no warning). Default: never
     * blocked. Return a translated message to block; the delete() action
     * shows it as an error flash and redirects back to the list instead
     * of removing the entity.
     */
    protected function getDeleteBlockedReason(object $entity): ?string
    {
        return null;
    }

    /**
     * Centralised breadcrumb builder. The trail reads:
     *   - on the LIST page:   Dashboard > <Entity plural>
     *   - on NEW/EDIT/VIEW:   Dashboard > <Entity plural> (link) > <current page>
     *
     * The list page passes `null` for the entity-list link (we're already
     * there); the other actions pass the list route so the breadcrumb
     * backtracks to it.
     *
     * @param list<array{label: string, path: ?string}> $extra
     * @return list<array{label: string, path: ?string}>
     */
    protected function buildTrail(?string $listLink, array $extra = []): array
    {
        $listLabel = $this->translator->trans('app.administration.crud.titles.list', [
            '%entity_plural%' => $this->getEntityLabelPlural(),
        ]);
        $trail = [
            ['label' => $this->translator->trans('app.administration.crud.breadcrumbs.dashboard'), 'path' => 'backoffice_dashboard'],
            ['label' => $listLabel, 'path' => $listLink],
        ];
        return [...$trail, ...$extra];
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
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

        $listTitle = $this->translator->trans('app.administration.crud.titles.list', [
            '%entity_plural%' => $this->getEntityLabelPlural(),
        ]);
        $newLabel = $this->translator->trans('app.administration.crud.new_button', [
            '%entity%' => $this->getEntityLabel(),
        ]);
        $emptyMessage = $this->translator->trans('app.administration.crud.empty', [
            '%entity_plural%' => strtolower($this->getEntityLabelPlural()),
        ]);

        return $this->render($this->getTemplatePrefix() . '/list.html.twig', [
            'items'              => $items,
            'columns'            => $this->getListColumns(),
            'title'              => $listTitle,
            'entity_label'       => $this->getEntityLabel(),
            'new_path'           => $this->getRoutePrefix() . '_new',
            'new_label'          => $newLabel,
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
            'empty_message'      => $emptyMessage,
            'trail'              => $this->buildTrail(null, [
                ['label' => $listTitle, 'path' => null],
            ]),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->newEntity();
        $form = $this->createForm($this->getFormType(), $entity, $this->getFormOptions(true));
        $this->preBindForm($form, $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->onFormSubmit($entity, $form, true);
            $this->em->persist($entity);
            $this->em->flush();
            $this->onCreateSuccess($entity);

            // Allow subclasses to override the generic success flash
            // (e.g. when `onCreateSuccess()` already added a richer one).
            // Return null to suppress the generic flash entirely.
            $flash = $this->getCreatedFlashMessage($entity);
            if ($flash !== null) {
                $this->addFlash('success', $flash);
            }

            return $this->redirectToRoute($this->getRoutePrefix() . '_list');
        }

        $title = $this->translator->trans('app.administration.crud.titles.new', [
            '%entity%' => $this->getEntityLabel(),
        ]);

        return $this->render($this->getTemplatePrefix() . '/new.html.twig', [
            'form'         => $form,
            'entity'       => $entity,
            'title'        => $title,
            'back_path'    => $this->getRoutePrefix() . '_list',
            'submit_label' => $this->translator->trans('app.administration.crud.create'),
            'trail'        => $this->buildTrail($this->getRoutePrefix() . '_list', [
                ['label' => $this->translator->trans('app.administration.crud.breadcrumbs.new'), 'path' => null],
            ]),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException(
                $this->translator->trans('app.shared.errors.not_found')
            );
        }

        $form = $this->createForm($this->getFormType(), $entity, $this->getFormOptions(false));
        $this->preBindForm($form, $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->onFormSubmit($entity, $form, false);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('app.administration.flash.updated', [
                '%entity%' => $this->getEntityLabel(),
            ]));

            return $this->redirectToRoute($this->getRoutePrefix() . '_list');
        }

        $title = $this->translator->trans('app.administration.crud.titles.edit', [
            '%display%' => $this->getEntityDisplayName($entity),
        ]);

        return $this->render($this->getTemplatePrefix() . '/edit.html.twig', [
            'form'         => $form,
            'entity'       => $entity,
            'title'        => $title,
            'back_path'    => $this->getRoutePrefix() . '_list',
            'submit_label' => $this->translator->trans('app.administration.crud.save'),
            'trail'        => $this->buildTrail($this->getRoutePrefix() . '_list', [
                ['label' => $title, 'path' => null],
            ]),
        ]);
    }

    #[Route('/{id}', name: 'view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException(
                $this->translator->trans('app.shared.errors.not_found')
            );
        }

        $title = $this->translator->trans('app.administration.crud.titles.view', [
            '%display%' => $this->getEntityDisplayName($entity),
        ]);

        return $this->render($this->getTemplatePrefix() . '/view.html.twig', [
            'entity'       => $entity,
            'title'        => $title,
            'entity_label' => $this->getEntityLabel(),
            'back_path'    => $this->getRoutePrefix() . '_list',
            'edit_path'    => $this->getRoutePrefix() . '_edit',
            'trail'        => $this->buildTrail($this->getRoutePrefix() . '_list', [
                ['label' => $title, 'path' => null],
            ]),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->getRequiredPermission());
        $this->assertValidCsrfToken($request, 'delete' . $id);

        $entity = $this->findEntity($id);
        if ($entity === null) {
            throw $this->createNotFoundException(
                $this->translator->trans('app.shared.errors.not_found')
            );
        }

        $blockedReason = $this->getDeleteBlockedReason($entity);
        if ($blockedReason !== null) {
            $this->addFlash('error', $blockedReason);
            return $this->redirectToRoute($this->getRoutePrefix() . '_list');
        }

        try {
            $this->em->remove($entity);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', $this->translator->trans('app.administration.crud.delete_blocked_generic', [
                '%entity%' => $this->getEntityLabel(),
            ]));
            return $this->redirectToRoute($this->getRoutePrefix() . '_list');
        }

        $this->addFlash('success', $this->translator->trans('app.administration.flash.deleted', [
            '%entity%' => $this->getEntityLabel(),
        ]));

        return $this->redirectToRoute($this->getRoutePrefix() . '_list');
    }
}
