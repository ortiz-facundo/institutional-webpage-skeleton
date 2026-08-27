<?php

declare(strict_types=1);

namespace App\Crud\BulkAction;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for a list-page bulk action (an operation applied to a
 * multi-row selection on a `with_bulk_checkbox`-enabled CRUD list).
 *
 * A bulk action is a toolbar-menu item that opens a modal (or directly
 * POSTs to a route) and operates on the current row selection. The
 * framework is intentionally menu-only: each action owns its own
 * form/controller/service. Adding a new bulk action = implement this
 * interface + create the supporting pieces; `BulkActionRegistry` picks
 * it up automatically via the `#[AutoconfigureTag]` below.
 *
 * All getters return already-translated strings when relevant so the
 * template can render directly — no `|trans` calls inside the menu
 * loop. The convention: actions accept the TranslatorInterface in
 * their constructor and resolve their labels at boot time.
 *
 * No controller in this skeleton has a concrete bulk action yet — this
 * interface + `BulkActionRegistry` exist so a downstream project can
 * add one without inventing the registry/permission-filtering plumbing
 * itself. The toolbar/modal UI and its JS are intentionally NOT part of
 * the skeleton until a first concrete action needs them.
 */
#[AutoconfigureTag('app.crud_bulk_action')]
interface BulkActionInterface
{
    /**
     * Stable identifier. Used as `data-crud-bulk-action="{{ id }}"` on
     * the menu-item button. Keep it kebab-case or snake_case; never
     * changes after the action ships (it becomes part of the audit
     * trail if the action ever wants to record which one was
     * triggered).
     */
    public function getId(): string;

    /**
     * The visible menu-item label. Pre-translated; the registry does
     * NOT translate on the way out.
     */
    public function getLabel(): string;

    /**
     * Heroicons outline key. Resolved by the menu partial against its
     * inlined `d="…"` string map.
     */
    public function getIcon(): string;

    /**
     * Permission string required to see + run this action. Returns
     * `null` for actions available to everyone (rare; reserved for
     * fully-read-only flows). The registry filters out actions whose
     * permission the current user does not hold.
     */
    public function getRequiredPermission(): ?string;

    /**
     * POST route name the action's modal submits to, or `null` when
     * the action is a one-click confirm with no extra form.
     */
    public function getEndpoint(): ?string;

    /**
     * Modal partial path to render on click, or `null` when the
     * action needs no modal (e.g. a single `<form>` POST with no
     * intermediate step).
     */
    public function getModalPartial(): ?string;

    /**
     * Theme token. One of 'amber' (default), 'rose' (destructive),
     * 'emerald' (affirmative). Drives the modal's accent border and
     * the menu item's hover colour.
     */
    public function getTheme(): string;

    /**
     * The submit-intent value the modal's primary button carries.
     * `null` when the action has no modal and no intent.
     */
    public function getIntent(): ?string;
}
