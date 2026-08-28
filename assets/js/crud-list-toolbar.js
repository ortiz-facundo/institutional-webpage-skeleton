/**
 * Generic CRUD list toolbar — filter popover + per-page auto-submit.
 *
 * Generalized from gestion-nova's Lead-list filter JS. Wires up any
 * list page built on `templates/shared/crud/_list_toolbar.html.twig`
 * + `_pagination.html.twig`:
 *
 *   1. Filter popover (the `<details>` disclosure). Native `<details>`
 *      handles open/close + keyboard. This adds a click-outside-to-close
 *      handler + Escape-key closes.
 *
 *   2. Popover form (currently just search, extensible to more fields
 *      by a future filter form). Does NOT auto-submit on change — the
 *      user edits the field(s) and clicks Apply (or presses Enter) to
 *      commit. The Apply button is `disabled` until the form has
 *      unsaved edits (differs from the values that produced the
 *      current page).
 *
 *   3. Pagination footer per-page control (the shared CustomSelect
 *      component) auto-submits its own small form on change.
 *
 * Markup contract:
 *   - <details data-crud-filter-details>              the popover root
 *   - <form data-crud-filter-form>                     any filter form
 *   - <form data-crud-filter-popover-form>              the popover form
 *                                                       (subset; only this
 *                                                       one runs dirty
 *                                                       tracking)
 *   - <a data-crud-filter-quick-clear>                 clear-all ✕ inside
 *                                                       the summary (has
 *                                                       onclick stopPropagation)
 *   - <button data-crud-filter-apply>                  Apply button
 *                                                       (disabled until dirty)
 *   - <input data-crud-filter-page>                    hidden page (resets to 1)
 *   - <span data-crud-filter-count>                    count badge (server-set)
 *   - <span data-crud-filter-dirty>                    dirty dot
 *   - <form data-crud-per-page-form>                   the pagination footer's
 *                                                       per-page form
 *
 * Pure vanilla IIFE — mirrors the convention used by every other
 * assets/js/*.js module.
 */

(function () {
    'use strict';

    /* ─────────────────────────────────────────────────────────────────
       Popover (filter disclosure) — close on outside click + Esc.
       ───────────────────────────────────────────────────────────────── */

    function bindPopoverCloseOnOutsideClick() {
        document.addEventListener('click', function (event) {
            const openDetails = document.querySelectorAll('details[data-crud-filter-details][open]');
            openDetails.forEach(function (d) {
                if (!d.contains(event.target)) {
                    d.removeAttribute('open');
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            const openDetails = document.querySelectorAll('details[data-crud-filter-details][open]');
            openDetails.forEach(function (d) { d.removeAttribute('open'); });
        });
    }

    /* ─────────────────────────────────────────────────────────────────
       Popover form — dirty-state tracking + Apply button enable.
       ───────────────────────────────────────────────────────────────── */

    function bindPopoverDirtyState() {
        const forms = document.querySelectorAll('[data-crud-filter-popover-form]');
        forms.forEach(function (form) {
            const applyButton = form.querySelector('[data-crud-filter-apply]');
            const dirtyDot = document.querySelector('[data-crud-filter-dirty]');
            if (!applyButton) return;

            const initial = snapshotForm(form);

            function refreshDirtyState() {
                const current = snapshotForm(form);
                const dirty = !shallowEqual(initial, current);

                applyButton.disabled = !dirty;
                if (dirtyDot) {
                    dirtyDot.classList.toggle('hidden', !dirty);
                }
            }

            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.addEventListener('input', refreshDirtyState);
                el.addEventListener('change', refreshDirtyState);
            });

            const search = form.querySelector('input[type="search"]');
            if (search) {
                search.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (!applyButton.disabled) {
                            applyButton.click();
                        }
                    }
                });
            }

            form.addEventListener('submit', function () {
                applyButton.disabled = true;
            });

            refreshDirtyState();
        });
    }

    /**
     * Snapshot the user-editable fields of the form into a flat
     * key→value map. Hidden fields (sort, direction, page) are
     * excluded — they don't participate in the user's filter edits.
     */
    function snapshotForm(form) {
        const snap = {};
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(function (el) {
            if (el.type === 'hidden') return;
            if (el.dataset.selectNative !== undefined) {
                snap[el.name] = readSelectValue(el);
                return;
            }
            if (!el.name) return;
            if (el.tagName === 'SELECT' && el.multiple) {
                snap[el.name] = readSelectValue(el);
                return;
            }
            if (el.type === 'checkbox' || el.type === 'radio') {
                snap[el.name] = el.checked ? '1' : '';
            } else {
                snap[el.name] = el.value || '';
            }
        });
        return snap;
    }

    function readSelectValue(el) {
        if (!el.multiple) return el.value || '';
        const values = Array.from(el.selectedOptions).map(function (o) { return o.value; });
        values.sort();
        return values.join(',');
    }

    function shallowEqual(a, b) {
        const aKeys = Object.keys(a);
        const bKeys = Object.keys(b);
        if (aKeys.length !== bKeys.length) return false;
        for (const k of aKeys) {
            if (a[k] !== b[k]) return false;
        }
        return true;
    }

    /* ─────────────────────────────────────────────────────────────────
       Auto-submit on per-page change. The per-page selector uses the
       shared CustomSelect component, which writes its chosen value
       into a hidden native <select data-select-native> and fires a
       `change` event on it — listen for that to trigger the form
       submit.
       ───────────────────────────────────────────────────────────────── */

    function bindPerPageAutoSubmit() {
        const forms = document.querySelectorAll('[data-crud-per-page-form]');
        forms.forEach(function (form) {
            const pageInput = form.querySelector('[data-crud-filter-page]');
            const customNativeSelects = form.querySelectorAll('select[data-select-native]');
            customNativeSelects.forEach(function (el) {
                el.addEventListener('change', function () {
                    if (pageInput) pageInput.value = '1';
                    requestAnimationFrame(function () { form.submit(); });
                });
            });
        });
    }

    /* ─────────────────────────────────────────────────────────────────
       Init.
       ───────────────────────────────────────────────────────────────── */

    function init() {
        bindPopoverCloseOnOutsideClick();
        bindPopoverDirtyState();
        bindPerPageAutoSubmit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
