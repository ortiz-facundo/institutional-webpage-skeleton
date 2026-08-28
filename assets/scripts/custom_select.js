/**
 * CustomSelect controller.
 *
 * A reusable custom-dropdown widget. Every `[data-controller-root="custom-select"]`
 * on the page becomes one instance. The component auto-detects the
 * operating mode from the data attributes on the root:
 *
 *   - AJAX mode  — root has `data-endpoint`. Selecting an option POSTs
 *     `application/x-www-form-urlencoded` to that URL with the chosen
 *     value (default form-field name: `value`) and a CSRF token
 *     (`_token` by default). The trigger pill updates OPTIMISTICALLY
 *     and reverts if the server rejects. On success it briefly shows
 *     a "Saved" line.
 *
 *   - Form mode  — root has no `data-endpoint`. Selecting an option
 *     writes its value into the hidden `<select data-select-native>`
 *     and fires a `change` event so the parent `<form>` and any
 *     validator listeners see it.
 *
 *   - Multi mode — root has `data-multiple="true"`. Each option click
 *     toggles its inclusion in the selection (chip-style trigger,
 *     checkboxes inside the listbox, the native `<select>` gets
 *     `multiple` and a name suffix of `[]`). The listbox stays open
 *     between selections.
 *
 *   - Filter     — root has `data-filter="true"`. A `<input type="search">`
 *     inside the listbox filters options by case-insensitive substring
 *     match on the option label. Auto-enabled in multi mode.
 *
 * Markup contract — see `templates/shared/custom_select.html.twig`.
 *
 * Behaviour (all modes):
 *
 *   1. Click trigger  →  toggle the listbox.
 *   2. Click outside  →  close (no focus changes).
 *   3. Click on the root label  →  open (and focus the trigger).
 *   4. Keyboard on trigger (open state):
 *        ↓/↑    navigate options
 *        Home   first option
 *        End    last option
 *        Enter / Space   select the focused option (toggle in multi)
 *        Esc    close (focus returns to trigger)
 *        Tab    close without focus changes (let focus move past)
 *   5. Mouse hover  →  option background tints (hover state).
 *
 * CSRF:
 *
 *   AJAX mode requires both the hidden CSRF token AND matching
 *   Origin / Referer headers. The fetch sends both.
 *
 * Tailwind JIT:
 *
 *   Every dynamic utility (e.g. the dot colour for the current
 *   status) is rendered server-side via `data-dot-classes` /
 *   `data-trigger-classes` on each option. The component reads
 *   those attributes at runtime — Tailwind's content glob picks
 *   every one of them up because every value also appears verbatim
 *   in the partial.
 *
 * Idempotent: each root is bound once via a marker attribute, so
 * the module can be imported multiple times without double-binding.
 */

class CustomSelectController {
    constructor(root) {
        this.root = root;
        this.endpoint = root.dataset.endpoint || '';
        this.payloadKey = root.dataset.payloadKey || 'value';
        this.csrfKey = root.dataset.csrfParamName || '_token';
        this.csrfToken = root.dataset.csrfToken || '';
        this.savedText = root.dataset.savedText || '';
        this.multiple = root.dataset.multiple === 'true';
        this.maxChips = parseInt(root.dataset.maxChips || '2', 10);
        // `data-listbox-position="up"` flips the listbox above the
        // trigger (for dropdowns near the bottom of the viewport —
        // e.g. the per-page selector at the end of a list). Default
        // is "down" (the listbox drops below the trigger).
        this.listboxPosition = root.dataset.listboxPosition === 'up' ? 'up' : 'down';
        // Single-select value comes from data-value; multi-select
        // pulls from the hidden <select>'s selectedOptions.
        this.value = this.multiple ? [] : (root.dataset.value || '');

        this.trigger = root.querySelector('[data-select-trigger]');
        this.listbox = root.querySelector('[data-select-listbox]');
        this.native = root.querySelector('[data-select-native]');
        this.labelEl = root.querySelector('[data-select-label]');
        this.dotEl = root.querySelector('[data-select-dot]');
        this.chipsEl = root.querySelector('[data-select-chips]');
        this.summaryEl = root.querySelector('[data-select-summary]');
        this.feedback = root.querySelector('[data-select-feedback]');
        this.filterInput = root.querySelector('[data-select-filter]');
        this.options = Array.from(root.querySelectorAll('[data-select-option]'));
        this.allToggleEl = root.querySelector('[data-select-all-toggle]');
        this.labelElRoot = root.querySelector('label');

        this.open = false;
        this.feedbackTimer = null;
        this.busy = false;

        if (!this.trigger || !this.listbox || !this.options.length) return;
        if (!this.labelEl && !this.chipsEl) return;

        // Apply the requested listbox position. The default CSS uses
        // `top-full` (drops below the trigger); flipping re-anchors
        // the listbox above. The transition's `transform-origin` is
        // also flipped so the scale-in animates from the new edge.
        if (this.listboxPosition === 'up') {
            this.listbox.classList.add('!top-auto', '!bottom-full', '!mt-0', '!mb-2', 'origin-bottom');
        }

        this.#initialSelectionFromNative();
        this.#setSelection(this.value, { announce: false });
        this.bind();
    }

    /**
     * Detect operating mode. AJAX if `data-endpoint` is set, otherwise form mode.
     */
    get mode() {
        return this.endpoint ? 'ajax' : 'form';
    }

    /**
     * Multi-mode pulls its initial selection from the hidden native
     * `<select multiple>`'s `selectedOptions` (Symfony writes `selected`
     * to the right `<option>`s on render). Single-mode uses data-value
     * — that path was already handled in the constructor.
     */
    #initialSelectionFromNative() {
        if (!this.multiple || !this.native) return;
        this.value = Array.from(this.native.selectedOptions).map((o) => o.value);
    }

    bind() {
        // Suppress the browser's default mouse-click → focus-on-button
        // behaviour. Without this, clicking the trigger focuses it,
        // which can auto-scroll the document (or any scrollable
        // ancestor in a narrow table cell). Tab/Shift+Tab and keyboard
        // activation (Enter / Space) still focus the trigger — only the
        // mouse path is suppressed. `e.preventDefault()` on `mousedown`
        // does NOT cancel the subsequent `click` event, so toggle()
        // still runs.
        this.trigger.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });
        this.trigger.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggle();
        });

        this.options.forEach((option) => {
            option.addEventListener('mousedown', (e) => {
                e.preventDefault();
            });
            option.addEventListener('click', (e) => {
                e.preventDefault();
                this.#pick(option);
            });
        });

        // Multi-select "Select all / Deselect all" toggle at the top
        // of the listbox. Clicking it picks every visible option
        // (when in "select" mode) or clears every selection (when in
        // "deselect" mode). The mode is computed from the current
        // selection state; it's recomputed on every pick.
        if (this.allToggleEl) {
            this.allToggleEl.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (this.allToggleEl.dataset.actionMode === 'deselect') {
                    this.#setSelection([], { announce: false });
                } else {
                    const allValues = this.options
                        .filter((o) => this.#isOptionVisible(o))
                        .map((o) => o.dataset.value);
                    this.#setSelection(allValues, { announce: false });
                }
            });
        }

        document.addEventListener('click', (e) => {
            if (!this.open) return;
            if (!this.root.contains(e.target)) this.close({ refocus: false });
        });

        this.trigger.addEventListener('keydown', (e) => this.#onTriggerKeydown(e));
        this.listbox.addEventListener('keydown', (e) => this.#onListboxKeydown(e));

        if (this.labelElRoot) {
            this.labelElRoot.addEventListener('click', (e) => {
                e.preventDefault();
                this.openList();
                this.trigger.focus({ preventScroll: true });
            });
        }

        if (this.filterInput) {
            this.filterInput.addEventListener('input', () => this.#applyFilter());
            this.filterInput.addEventListener('keydown', (e) => e.stopPropagation());
            this.filterInput.addEventListener('click', (e) => e.stopPropagation());
            this.filterInput.addEventListener('mousedown', (e) => {
                e.preventDefault();
            });
        }

        if (this.mode === 'form' && this.native && this.native.form) {
            this.native.form.addEventListener('reset', () => {
                if (this.multiple) {
                    this.value = Array.from(this.native.selectedOptions).map((o) => o.value);
                } else {
                    this.value = this.native.value || '';
                }
                this.#setSelection(this.value, { announce: false });
            });
        }
    }

    // ─── listbox open / close ──────────────────────────────────────

    openList() {
        if (this.open) return;
        this.open = true;
        this.trigger.setAttribute('aria-expanded', 'true');
        this.trigger.dataset.open = 'true';
        this.listbox.dataset.open = 'true';
        this.listbox.removeAttribute('inert');

        this.#positionListbox();

        if (this.filterInput) {
            this.filterInput.value = '';
            this.#applyFilter();
            this.filterInput.focus({ preventScroll: true });
            return;
        }
        const target =
            this.options.find((o) => o.dataset.selected === 'true') ||
            this.options[0];
        if (target) target.focus({ preventScroll: true });
    }

    /**
     * Compute viewport-position and flip the listbox above the
     * trigger when there isn't enough room below. The `data-listbox-
     * position="up"` static opt-in from the partial stays
     * untouched — this method only flips dynamically.
     */
    #positionListbox() {
        if (this.listboxPosition === 'up') {
            this.listbox.classList.add('!top-auto', '!bottom-full', '!mt-0', '!mb-2', 'origin-bottom');
            return;
        }
        const triggerRect = this.trigger.getBoundingClientRect();
        const listboxMaxHeight = 240; // ~5 options × 48px
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const spaceAbove = triggerRect.top;
        const FLIP_HEADROOM = 16;
        if (spaceBelow < listboxMaxHeight + FLIP_HEADROOM
            && spaceAbove > spaceBelow + FLIP_HEADROOM) {
            this.listbox.classList.add('!top-auto', '!bottom-full', '!mt-0', '!mb-2', 'origin-bottom');
        } else {
            this.listbox.classList.remove('!top-auto', '!bottom-full', '!mt-0', '!mb-2', 'origin-bottom');
        }
    }

    close({ refocus = true } = {}) {
        if (!this.open) return;
        this.open = false;
        this.trigger.setAttribute('aria-expanded', 'false');
        delete this.trigger.dataset.open;
        delete this.listbox.dataset.open;
        this.listbox.setAttribute('inert', '');
        if (refocus) this.trigger.focus({ preventScroll: true });
    }

    toggle() {
        if (this.open) this.close();
        else this.openList();
    }

    // ─── selection ─────────────────────────────────────────────────

    async #pick(option) {
        if (!option || this.busy) return;
        const value = option.dataset.value;

        if (this.multiple) {
            const next = this.value.includes(value)
                ? this.value.filter((v) => v !== value)
                : this.value.concat([value]);
            this.#setSelection(next, { announce: true });
            if (this.mode === 'form' && this.native) {
                this.native.dispatchEvent(new Event('input', { bubbles: true }));
                this.native.dispatchEvent(new Event('change', { bubbles: true }));
            }
            option.focus();
            return;
        }

        if (!value || value === this.value) {
            this.close();
            return;
        }

        this.#setSelection(value, { announce: true });
        this.close();

        if (this.mode === 'form') {
            if (this.native) {
                this.native.dispatchEvent(new Event('input', { bubbles: true }));
                this.native.dispatchEvent(new Event('change', { bubbles: true }));
            }
            this.value = value;
            return;
        }

        await this.#commitAjax(value);
    }

    /**
     * AJAX commit. The pill was already updated optimistically by
     * setSelection(); this method snapshots and reverts on error.
     */
    async #commitAjax(value) {
        this.busy = true;
        const snapshot = {
            value: this.value,
            label: this.labelEl ? this.labelEl.textContent : '',
            dotClasses: this.dotEl ? this.dotEl.className : '',
        };

        try {
            const body = new URLSearchParams();
            body.set(this.payloadKey, value);
            if (this.csrfToken) body.set(this.csrfKey, this.csrfToken);

            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'Origin': window.location.origin,
                    'Referer': window.location.href,
                },
                body,
                credentials: 'same-origin',
            });

            const payload = await this.#safeJson(response);

            if (!response.ok || (payload && payload.ok === false)) {
                throw new Error(
                    (payload && payload.message) || `HTTP ${response.status}`
                );
            }

            if (payload && typeof payload.label === 'string' && this.payloadKey in payload) {
                this.#setLabelOnly(payload.label);
            }
            this.#flash('saved');
            this.value = value;
        } catch (err) {
            this.value = snapshot.value;
            this.#setSelection(snapshot.value, { announce: false });
            this.#setLabelOnly(snapshot.label);
            if (this.dotEl) this.dotEl.className = snapshot.dotClasses;
            this.#flash('error', err.message || '');
        } finally {
            this.busy = false;
        }
    }

    /**
     * Apply a value locally: update `value`, mirror into the hidden
     * native <select> (form mode), update the trigger label / chips,
     * the dot, the option's `data-selected` flags, and the button's
     * `data-placeholder` flag.
     *
     * Multi-mode expects a list; single-mode expects a scalar. The
     * helper coerces non-iterables to an empty list so the rest of
     * the function can be mode-agnostic.
     */
    #setSelection(value, { announce } = {}) {
        const selectedValues = this.multiple
            ? (Array.isArray(value) ? value : (value ? [value] : []))
            : (Array.isArray(value) ? (value[0] || '') : (value || ''));
        this.value = selectedValues;
        if (!this.multiple) {
            this.root.dataset.value = selectedValues;
        }

        const hasAny = this.multiple
            ? selectedValues.length > 0
            : (selectedValues !== '' && selectedValues != null);
        this.trigger.dataset.placeholder = hasAny ? 'false' : 'true';

        this.options.forEach((opt) => {
            const isMatch = this.multiple
                ? selectedValues.includes(opt.dataset.value)
                : opt.dataset.value === selectedValues;
            const flag = isMatch ? 'true' : 'false';
            opt.dataset.selected = flag;
            opt.setAttribute('aria-selected', flag);
        });

        if (this.multiple) {
            this.#renderChips(selectedValues);
            this.#refreshSelectAllToggle(selectedValues);
        } else {
            this.#renderSingle(selectedValues);
        }

        if (this.native) {
            if (this.multiple) {
                Array.from(this.native.options).forEach((opt) => {
                    opt.selected = selectedValues.includes(opt.value);
                });
            } else {
                this.native.value = selectedValues || '';
            }
        }
    }

    /**
     * Recompute the "Select all / Deselect all" toggle state for
     * multi-select listboxes. The toggle's action mode is "select"
     * when ANY visible option is unselected, "deselect" when every
     * visible option is selected. The label and icon flip in lock-step.
     */
    #refreshSelectAllToggle(selectedValues) {
        if (!this.allToggleEl) return;
        const visibleOptions = this.options.filter((o) => this.#isOptionVisible(o));
        const allSelected = visibleOptions.length > 0
            && visibleOptions.every((o) => selectedValues.includes(o.dataset.value));
        const mode = allSelected ? 'deselect' : 'select';
        this.allToggleEl.dataset.actionMode = mode;

        const labelEl = this.allToggleEl.querySelector('[data-select-all-label]');
        if (labelEl) {
            const next = this.allToggleEl.dataset[`label${mode[0].toUpperCase()}${mode.slice(1)}`];
            if (next) labelEl.textContent = next;
        }

        const iconWrap = this.allToggleEl.querySelector('[data-select-all-icon]');
        if (iconWrap) {
            iconWrap.innerHTML = mode === 'deselect'
                ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>';
        }
    }

    /**
     * Whether an option is currently visible in the listbox — i.e.
     * not filtered out by the search input.
     */
    #isOptionVisible(option) {
        return option.dataset.hidden !== 'true';
    }

    #renderSingle(value) {
        if (!this.labelEl) return;
        const match = this.options.find((o) => o.dataset.value === value) || null;
        if (match) {
            const labelEl = match.querySelector('[data-select-option-label]');
            this.labelEl.textContent = labelEl ? labelEl.textContent : match.textContent.trim();
            if (this.dotEl) {
                const dotClasses = match.dataset.dotClasses || '';
                if (dotClasses) {
                    const base = 'h-2 w-2 shrink-0 rounded-full';
                    this.dotEl.className = `${base} ${dotClasses}`;
                    this.dotEl.classList.remove('hidden');
                } else {
                    this.dotEl.classList.add('hidden');
                }
            }
        } else {
            this.labelEl.textContent = this.trigger.dataset.placeholderLabel || '';
        }
    }

    /**
     * Multi-mode trigger rendering. The server already painted the
     * initial chips server-side; this rebuilds them on every change
     * so they stay in sync with the toggled state.
     */
    #renderChips(selectedValues) {
        if (!this.chipsEl) return;
        this.chipsEl.innerHTML = '';
        const max = this.maxChips;
        const chosen = this.options.filter((o) => selectedValues.includes(o.dataset.value));
        chosen.slice(0, max).forEach((opt) => {
            const dotClasses = opt.dataset.dotClasses || '';
            const labelEl = opt.querySelector('[data-select-option-label]');
            const label = labelEl ? labelEl.textContent : opt.textContent.trim();
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-800';
            if (dotClasses) {
                const dot = document.createElement('span');
                dot.setAttribute('aria-hidden', 'true');
                dot.className = `h-1.5 w-1.5 shrink-0 rounded-full ${dotClasses}`;
                chip.appendChild(dot);
            }
            const text = document.createElement('span');
            text.textContent = label;
            chip.appendChild(text);
            this.chipsEl.appendChild(chip);
        });
        if (this.summaryEl) {
            const overflow = chosen.length - max;
            if (overflow > 0) {
                this.summaryEl.textContent = `+${overflow}`;
                this.summaryEl.classList.remove('hidden');
            } else {
                this.summaryEl.textContent = '';
                this.summaryEl.classList.add('hidden');
            }
        }
    }

    /**
     * Update only the trigger's label text (used after a server
     * response to honour the canonical server-returned label).
     */
    #setLabelOnly(text) {
        if (this.labelEl) this.labelEl.textContent = text;
    }

    // ─── inline filter ─────────────────────────────────────────────

    #applyFilter() {
        if (!this.filterInput) return;
        const needle = this.filterInput.value.trim().toLowerCase();
        let visible = 0;
        this.options.forEach((opt) => {
            const labelEl = opt.querySelector('[data-select-option-label]');
            const text = (labelEl ? labelEl.textContent : opt.textContent).trim().toLowerCase();
            const match = needle === '' || text.includes(needle);
            opt.classList.toggle('hidden', !match);
            if (match) visible += 1;
        });
        this.listbox.dataset.empty = visible === 0 ? 'true' : 'false';
    }

    // ─── inline feedback (AJAX mode) ────────────────────────────────

    #flash(kind, message = '') {
        if (!this.feedback) return;
        clearTimeout(this.feedbackTimer);

        if (kind === 'saved') {
            this.feedback.textContent = this.savedText || 'Saved';
            this.feedback.className =
                'absolute -bottom-5 left-0 text-xs font-medium text-emerald-600';
        } else {
            this.feedback.textContent = message || 'Request failed';
            this.feedback.className =
                'absolute -bottom-5 left-0 text-xs font-medium text-rose-600';
        }

        this.feedbackTimer = setTimeout(() => {
            this.feedback.classList.add('hidden');
            this.feedback.textContent = '';
        }, 1800);
    }

    // ─── keyboard ──────────────────────────────────────────────────

    #onTriggerKeydown(e) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.openList();
            return;
        }
        if (e.key === 'Escape' && this.open) {
            this.close();
        }
    }

    #onListboxKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            this.close();
            return;
        }
        if (e.key === 'Tab') {
            this.close({ refocus: false });
            return;
        }

        const visibleOptions = this.options.filter(
            (o) => !o.classList.contains('hidden')
        );
        const idx = visibleOptions.indexOf(document.activeElement);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = visibleOptions[Math.min(idx + 1, visibleOptions.length - 1)];
            if (next) next.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = visibleOptions[Math.max(idx - 1, 0)];
            if (prev) prev.focus();
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (visibleOptions[0]) visibleOptions[0].focus();
        } else if (e.key === 'End') {
            e.preventDefault();
            if (visibleOptions.length) visibleOptions[visibleOptions.length - 1].focus();
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (idx >= 0) this.#pick(visibleOptions[idx]);
        }
    }

    // ─── helpers ───────────────────────────────────────────────────

    async #safeJson(response) {
        try {
            return await response.json();
        } catch (_e) {
            return null;
        }
    }
}

// Public API. `init()` walks the whole document; callers that
// already have a row in hand can scope it via `initWithin(root)` to
// avoid cross-instance interference on dynamic pages.
function init() {
    document.querySelectorAll('[data-controller-root="custom-select"]').forEach((root) => {
        if (root.__customSelectInstance) return;
        root.__customSelectInstance = new CustomSelectController(root);
        root.dataset.customSelectBound = '1';
    });
}

function initWithin(scope) {
    (scope || document).querySelectorAll('[data-controller-root="custom-select"]').forEach((root) => {
        if (root.__customSelectInstance) return;
        root.__customSelectInstance = new CustomSelectController(root);
        root.dataset.customSelectBound = '1';
    });
}

window.CustomSelect = { init, initWithin, CustomSelectController };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { CustomSelectController, init, initWithin };
