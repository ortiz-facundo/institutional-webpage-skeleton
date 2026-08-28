/**
 * Generic CRUD list — bulk selection + bulk-actions menu.
 *
 * Generalized from gestion-nova's Lead-list bulk JS. Wires the per-row
 * checkboxes on any list page (rendered by
 * `templates/shared/crud/_list.html.twig` when the consumer passes
 * `with_bulk_checkbox: true`) to the right-side bulk-actions disclosure
 * (rendered by `templates/shared/crud/_bulk_actions_menu.html.twig`,
 * included by `_list_toolbar.html.twig` when `bulk_actions` is
 * non-empty).
 *
 * Responsibilities:
 *   1. Track which row checkboxes are checked; reflect the count in
 *      the disclosure summary (badge + label).
 *   2. Toggle the disclosure's popover body between the "select rows
 *      first" empty state and the menu list, depending on the count.
 *   3. "Select all" header checkbox: toggle every row on the current
 *      page; show indeterminate when only some are checked.
 *   4. On menu-item click: write the current selection into the
 *      target modal's hidden ids field (JSON-encoded), update the
 *      modal's count placeholder, and open the dialog.
 *
 * Markup contract (server-side renders these):
 *   - <input data-crud-bulk-select-all>          header checkbox (page-wide)
 *   - <input data-crud-bulk-row>                 per-row checkbox (value = id)
 *   - <details data-crud-bulk-menu-details>      the disclosure root
 *   - <summary data-crud-bulk-menu-summary>      the disclosure trigger
 *   - <span  data-crud-bulk-menu-count>          count badge inside the trigger
 *   - <span  data-crud-bulk-menu-summary-text>   count label inside the popover,
 *            data-label-one / data-label-other    pre-translated templates
 *            (data-label-other contains the literal token `%count%`)
 *   - <div   data-crud-bulk-menu-empty>          "select rows first" hint
 *   - <ul    data-crud-bulk-menu-list>            the menu list (hidden when empty)
 *   - <button data-crud-bulk-menu-item="<id>">   per-action menu item
 *            data-modal-id="<dialog-id>"
 *            data-modal-intent="<intent>"
 *   - <dialog id="<dialog-id>">                   the modal <dialog>, owned by
 *                                                  whatever concrete bulk action
 *                                                  registered it
 *   - <input data-crud-bulk-ids>                 hidden ids field inside a modal
 *   - <span  data-crud-bulk-modal-count>         count placeholder inside a modal
 *
 * A concrete BulkActionInterface implementation is responsible for its
 * own modal markup / controller — this module only maintains the
 * shared selection state and dispatches the click.
 *
 * Pure vanilla IIFE — mirrors the convention used by every other
 * assets/js/*.js module.
 */

(function () {
    'use strict';

    function getRowCheckboxes() {
        return Array.from(document.querySelectorAll('[data-crud-bulk-row]'));
    }

    function getSelectAllCheckbox() {
        return document.querySelector('[data-crud-bulk-select-all]');
    }

    function getMenuSummary() {
        return document.querySelector('[data-crud-bulk-menu-summary]');
    }

    function getMenuCount() {
        return document.querySelector('[data-crud-bulk-menu-count]');
    }

    function getMenuList() {
        return document.querySelector('[data-crud-bulk-menu-list]');
    }

    function getMenuEmpty() {
        return document.querySelector('[data-crud-bulk-menu-empty]');
    }

    function getMenuSummaryText() {
        return document.querySelector('[data-crud-bulk-menu-summary-text]');
    }

    function getSelectedIds() {
        return getRowCheckboxes()
            .filter((cb) => cb.checked)
            .map((cb) => parseInt(cb.value, 10))
            .filter((id) => Number.isInteger(id) && id > 0);
    }

    function syncSelectAllState() {
        const selectAll = getSelectAllCheckbox();
        if (!selectAll) return;
        const rows = getRowCheckboxes();
        const checkedCount = rows.filter((cb) => cb.checked).length;
        if (checkedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checkedCount === rows.length) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }

    /**
     * Reflect the current selection count in every visible place: the
     * disclosure summary badge, the menu header text, the "select
     * all" header state. Also flips the disclosure between the
     * "select rows first" empty state and the menu list.
     */
    function refreshMenu() {
        const ids = getSelectedIds();
        const count = ids.length;

        const badge = getMenuCount();
        if (badge) {
            badge.textContent = String(count);
            badge.classList.toggle('hidden', count === 0);
        }

        const summaryText = getMenuSummaryText();
        if (summaryText) {
            const template = count === 1
                ? summaryText.dataset.labelOne
                : summaryText.dataset.labelOther;
            if (template) {
                summaryText.textContent = template.replace('%count%', String(count));
            }
        }

        // Tint the summary when there's a selection; the base classes
        // keep it stone-coloured otherwise. Mirrors the active-filter
        // state on the filter disclosure.
        const summary = getMenuSummary();
        if (summary) {
            const activeClasses = ['border-amber-700', 'text-amber-800', 'hover:bg-amber-50'];
            if (count > 0) {
                summary.classList.add(...activeClasses);
            } else {
                summary.classList.remove(...activeClasses);
            }
        }

        const list = getMenuList();
        const empty = getMenuEmpty();
        if (list && empty) {
            list.classList.toggle('hidden', count === 0);
            empty.classList.toggle('hidden', count !== 0);
        }

        syncSelectAllState();
    }

    /**
     * Write the current selection to every modal's hidden ids field
     * and update every modal's count placeholder. Done on every
     * menu-item click so the latest state wins even if the user has
     * unchecked a row since opening the disclosure.
     */
    function syncAllModals() {
        const ids = getSelectedIds();
        const json = JSON.stringify(ids);
        document.querySelectorAll('[data-crud-bulk-ids]').forEach((input) => {
            input.value = json;
        });
        document.querySelectorAll('[data-crud-bulk-modal-count]').forEach((el) => {
            el.textContent = String(ids.length);
        });
    }

    function openModalFromMenuItem(button) {
        const ids = getSelectedIds();
        if (ids.length === 0) {
            return; // empty-state branch — no-op
        }
        syncAllModals();
        const modalId = button.dataset.modalId;
        if (!modalId) return;
        const dialog = document.getElementById(modalId);
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }

    function bindRowCheckboxes() {
        getRowCheckboxes().forEach((cb) => {
            cb.addEventListener('change', refreshMenu);
        });
    }

    function bindSelectAll() {
        const selectAll = getSelectAllCheckbox();
        if (!selectAll) return;
        selectAll.addEventListener('change', () => {
            const target = selectAll.checked;
            getRowCheckboxes().forEach((cb) => { cb.checked = target; });
            refreshMenu();
        });
    }

    function bindMenuItems() {
        document.querySelectorAll('[data-crud-bulk-menu-item]').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                openModalFromMenuItem(btn);
            });
        });
    }

    function bindBulkForms() {
        document.querySelectorAll('[data-crud-bulk-form]').forEach((form) => {
            form.addEventListener('submit', () => {
                syncAllModals();
            });
        });
    }

    /**
     * Close the bulk-actions disclosure on outside click + Escape,
     * mirroring the filter disclosure's popover behaviour (see
     * `assets/js/crud-list-toolbar.js`). Same shape so the two
     * popovers feel like a matched pair.
     */
    function bindMenuCloseOnOutsideClick() {
        document.addEventListener('click', (event) => {
            const openDetails = document.querySelectorAll(
                'details[data-crud-bulk-menu-details][open]'
            );
            openDetails.forEach((d) => {
                if (!d.contains(event.target)) {
                    d.removeAttribute('open');
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const openDetails = document.querySelectorAll(
                'details[data-crud-bulk-menu-details][open]'
            );
            openDetails.forEach((d) => {
                d.removeAttribute('open');
            });
        });
    }

    function init() {
        // No-op when the page has no bulk checkboxes at all (most
        // lists — bulk actions are opt-in per controller).
        if (getRowCheckboxes().length === 0 && !getSelectAllCheckbox()) return;
        bindRowCheckboxes();
        bindSelectAll();
        bindMenuItems();
        bindBulkForms();
        bindMenuCloseOnOutsideClick();
        refreshMenu();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
