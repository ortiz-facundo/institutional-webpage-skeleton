<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\BulkAction\BulkActionRegistry;
use App\Security\CsrfProtectedActionTrait;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
 *   - row-action buttons (view/edit/delete/impersonate/resend) are
 *     gated in Twig via `is_granted()` against the `get{Action}Permission()`
 *     hooks below — see `templates/shared/crud/_row_actions.html.twig`.
 *
 * List (`list()`) capabilities — search, sortable columns, pagination,
 * an opt-in filter form, and an opt-in bulk-action registry — are all
 * OFF by default (empty/null hooks) and reproduce today's exact
 * behaviour (`findBy([], ['id' => 'ASC'])`, unpaginated) until a
 * concrete controller opts in by overriding the relevant hook. To add
 * search/filtering to a new entity: override `isSearchEnabled()` +
 * `applySearch()` for a plain text search, or `getFilterFormType()` +
 * `applyFilterCriteria()` for a richer filter form — build the form as
 * `method: 'GET'`, `csrf_protection: false`, and give it an empty
 * `getBlockPrefix()` so field names are bare query params (see
 * gestion-nova's `LeadFilterType` for a worked example of this
 * convention; that project's Lead list is also the reference
 * implementation this generic infrastructure was extracted from).
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
    use CrudListQueryTrait;

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

    /** @return list<array{label:string,path:string,class?:string,sortable?:bool,sort_key?:string,cell_partial?:string}> */
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
     * the button entirely (the entity has no such route). Default: null.
     * Visibility beyond route-existence (permission + self/admin/active
     * checks) is decided in Twig — see `getImpersonatePermission()` and
     * `_row_actions.html.twig`.
     */
    protected function getImpersonatePath(): ?string
    {
        return null;
    }

    /**
     * Permission required to see the impersonate button, or null for
     * unrestricted. Checked via `is_granted()` directly in
     * `_row_actions.html.twig`.
     */
    protected function getImpersonatePermission(): ?string
    {
        return null;
    }

    /** Voter attribute for per-row impersonation eligibility, if applicable. */
    protected function getImpersonateAttribute(): ?string
    {
        return null;
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
     * Permission required to see the resend button, or null for
     * unrestricted.
     */
    protected function getResendPermission(): ?string
    {
        return null;
    }

    /** Voter attribute for per-row resend eligibility, if applicable. */
    protected function getResendAttribute(): ?string
    {
        return null;
    }

    /**
     * Permission required to see the "New" button on the list page, or
     * null (default) for unrestricted.
     */
    protected function getNewPermission(): ?string
    {
        return null;
    }

    /**
     * Permission required to see the view button, or null (default) for
     * unrestricted — every row that reaches the list can be viewed.
     */
    protected function getViewPermission(): ?string
    {
        return null;
    }

    /**
     * Permission required to see the edit button, or null (default) for
     * unrestricted.
     */
    protected function getEditPermission(): ?string
    {
        return null;
    }

    /**
     * Permission required to see the delete button, or null (default)
     * for unrestricted. Useful when the page-level gate is broader than
     * the delete gate (e.g. everyone with VIEW_X can browse, but only
     * EDIT_X can delete).
     */
    protected function getDeletePermission(): ?string
    {
        return null;
    }

    /** Voter attribute for per-row delete eligibility, if applicable. */
    protected function getDeleteAttribute(): ?string
    {
        return null;
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
     * Seed QueryBuilder for `list()`. Default selects the whole entity
     * under alias `e`. Override to add joins/select — `applySearch()`
     * / `applyFilterCriteria()` add WHEREs to whatever this returns, and
     * `getSortableColumns()` / `getDefaultSort()` expressions must use
     * whatever alias this method chooses.
     */
    protected function buildListQueryBuilder(): QueryBuilder
    {
        return $this->em->getRepository($this->getEntityClass())->createQueryBuilder('e');
    }

    /**
     * Sortable columns for `list()`. Empty (default) = no column is
     * sortable, `_list.html.twig` renders every header as a plain
     * `<th>`. Map of public `?sort=` key => DQL-safe expression (e.g.
     * `['email' => 'e.email']`) — never interpolate the raw query
     * param into DQL directly; this whitelist is what makes that safe.
     *
     * @return array<string, string>
     */
    protected function getSortableColumns(): array
    {
        return [];
    }

    /**
     * Fallback order applied when no valid `?sort=` is present. Default
     * reproduces the pre-pagination behaviour (`id ASC`). Same shape as
     * `getSortableColumns()`'s values: DQL-safe expression => direction.
     *
     * @return array<string, string>
     */
    protected function getDefaultSort(): array
    {
        return ['e.id' => 'ASC'];
    }

    /**
     * Opt-in: when true, `list()` paginates via `?page=`/`?per_page=`
     * instead of returning the full result set. Default false
     * reproduces today's unpaginated behaviour.
     */
    protected function isPaginationEnabled(): bool
    {
        return false;
    }

    protected function getDefaultPerPage(): int
    {
        return 25;
    }

    /** @return list<int> */
    protected function getPerPageOptions(): array
    {
        return [10, 25, 50];
    }

    /**
     * Opt-in: when true, `list()` reads `?search=` and calls
     * `applySearch()` with the trimmed, non-empty term.
     */
    protected function isSearchEnabled(): bool
    {
        return false;
    }

    /**
     * Add a WHERE to `$qb` for the given non-empty search term. Default
     * no-op — subclasses that set `isSearchEnabled(): true` must
     * override this.
     */
    protected function applySearch(QueryBuilder $qb, string $term): void
    {
    }

    protected function getSearchPlaceholder(): string
    {
        return $this->translator->trans('app.administration.crud.search_placeholder');
    }

    /**
     * FQCN of a GET-bound, unmapped FormType for richer filtering, or
     * null (default) to disable. See this class's docblock for the
     * recommended FormType shape.
     */
    protected function getFilterFormType(): ?string
    {
        return null;
    }

    /**
     * Add WHEREs to `$qb` from the bound filter form. Only called when
     * `getFilterFormType()` returns non-null. Default no-op.
     */
    protected function applyFilterCriteria(QueryBuilder $qb, FormInterface $filterForm): void
    {
    }

    /**
     * Whether the bound filter form currently represents at least one
     * active filter (drives the "no results match your filters" empty
     * state vs the generic empty state). Default false — subclasses
     * with a filter form should override.
     */
    protected function filterFormHasActiveFilters(FormInterface $filterForm): bool
    {
        return false;
    }

    /**
     * Opt-in bulk-action registry for this list, or null (default) to
     * disable the bulk-selection checkbox column entirely. When
     * non-null, `_list.html.twig` renders `with_bulk_checkbox: true`
     * and the template gets `bulk_actions` = the registry's
     * permission-filtered list for the current user.
     */
    protected function getBulkActionRegistry(): ?BulkActionRegistry
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

        $qb = $this->buildListQueryBuilder();

        $searchTerm = '';
        if ($this->isSearchEnabled()) {
            $searchTerm = trim((string) $request->query->get('search', ''));
            if ($searchTerm !== '') {
                $this->applySearch($qb, $searchTerm);
            }
        }

        $filterFormView = null;
        $filterForm = null;
        $filterFormType = $this->getFilterFormType();
        if ($filterFormType !== null) {
            $filterForm = $this->createForm($filterFormType, null, [
                'method' => 'GET',
                'csrf_protection' => false,
            ]);
            $filterForm->handleRequest($request);
            $this->applyFilterCriteria($qb, $filterForm);
            $filterFormView = $filterForm->createView();
        }

        $sortableColumns = $this->getSortableColumns();
        $sortField = $this->resolveSortField($request->query->get('sort'), $sortableColumns);
        $sortDir = $this->resolveSortDirection($sortField, $request->query->get('direction'));
        if ($sortField !== null && $sortDir !== null) {
            $qb->orderBy($sortableColumns[$sortField], $sortDir);
        } else {
            foreach ($this->getDefaultSort() as $expr => $dir) {
                $qb->addOrderBy($expr, $dir);
            }
        }

        $paginationEnabled = $this->isPaginationEnabled();
        if ($paginationEnabled) {
            $page = $this->resolvePage($request->query->get('page'));
            $perPage = $this->resolvePerPage($request->query->get('per_page'), $this->getPerPageOptions(), $this->getDefaultPerPage());

            $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
            $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
            // Output walkers generate a correct COUNT when the source
            // query has any JOINs — see LeadRepository::searchAndFilter()
            // in gestion-nova, the reference implementation this was
            // generalized from, for the over-count failure mode this
            // avoids.
            $paginator->setUseOutputWalkers(true);

            $total = count($paginator);
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                // Stale page (e.g. a delete trimmed the list while the
                // user was on a later page) — clamp instead of showing
                // a misleading empty result.
                $page = $totalPages;
                $qb->setFirstResult(($page - 1) * $perPage);
                $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
                $paginator->setUseOutputWalkers(true);
            }
            $items = iterator_to_array($paginator, false);
        } else {
            $items = $qb->getQuery()->getResult();
            $page = 1;
            $perPage = count($items);
            $total = count($items);
            $totalPages = 1;
        }

        $hasAnyFilter = $searchTerm !== '' || ($filterForm !== null && $this->filterFormHasActiveFilters($filterForm));

        $csrf = $this->container->get('security.csrf.token_manager');

        // CSRF tokens are generated for every row on the current page,
        // for every action whose route exists at all — visibility from
        // there on is decided entirely in Twig (permission + row-state
        // checks), not by conditionally omitting tokens.
        $deleteTokens = [];
        $impersonatePath = $this->getImpersonatePath();
        $impersonateTokens = [];
        $resendPath = $this->getResendConfirmationPath();
        $resendTokens = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $deleteTokens[$id] = $csrf->getToken('delete' . $id)->getValue();
            if ($impersonatePath !== null) {
                $impersonateTokens[$id] = $csrf->getToken('impersonate' . $id)->getValue();
            }
            if ($resendPath !== null) {
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

        $bulkRegistry = $this->getBulkActionRegistry();

        return $this->render($this->getTemplatePrefix() . '/list.html.twig', [
            'items'              => $items,
            'columns'            => $this->getListColumns(),
            'title'              => $listTitle,
            'entity_label'       => $this->getEntityLabel(),
            'new_path'           => $this->getRoutePrefix() . '_new',
            'new_label'          => $newLabel,
            'new_permission'     => $this->getNewPermission(),
            'view_path'          => $this->getRoutePrefix() . '_view',
            'view_permission'    => $this->getViewPermission(),
            'edit_path'          => $this->getRoutePrefix() . '_edit',
            'edit_permission'    => $this->getEditPermission(),
            'delete_path'        => $this->getRoutePrefix() . '_delete',
            'delete_tokens'      => $deleteTokens,
            'delete_permission'  => $this->getDeletePermission(),
            'delete_attribute'   => $this->getDeleteAttribute(),
            'impersonate_path'         => $impersonatePath,
            'impersonate_tokens'       => $impersonateTokens,
            'impersonate_permission'   => $this->getImpersonatePermission(),
            'impersonate_attribute'    => $this->getImpersonateAttribute(),
            'resend_path'              => $resendPath,
            'resend_tokens'            => $resendTokens,
            'resend_permission'        => $this->getResendPermission(),
            'resend_attribute'         => $this->getResendAttribute(),
            'empty_message'      => $emptyMessage,
            'search_enabled'     => $this->isSearchEnabled(),
            'search_term'        => $searchTerm,
            'search_placeholder' => $this->getSearchPlaceholder(),
            'filter_form'        => $filterFormView,
            'sort_field'         => $sortField,
            'sort_dir'           => $sortDir,
            'current_url'        => $request->getRequestUri(),
            'current_params'     => $request->query->all(),
            'page'               => $page,
            'per_page'           => $perPage,
            'per_page_options'   => $this->getPerPageOptions(),
            'total'              => $total,
            'total_pages'        => $totalPages,
            'has_any_filter'     => $hasAnyFilter,
            'with_bulk_checkbox' => $bulkRegistry !== null,
            'bulk_actions'       => $bulkRegistry?->getVisibleForCurrentUser() ?? [],
            // `buildTrail(null, …)` already appends the list label as
            // the trail's last (unlinked) segment when `$listLink` is
            // null — no `extra` here, or it duplicates ("Dashboard >
            // Users > Users").
            'trail'              => $this->buildTrail(null),
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

        $deleteAttribute = $this->getDeleteAttribute();
        if ($deleteAttribute !== null) {
            $this->denyAccessUnlessGranted($deleteAttribute, $entity);
        }

        $blockedReason = $deleteAttribute === null ? $this->getDeleteBlockedReason($entity) : null;
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
