import Sortable from 'sortablejs';
import { initWithin as initCustomSelect } from './custom_select.js';
import { initWithin as initTagsInput } from './tags_input.js';

/** Entity-agnostic CollectionType table: append, remove and reorder rows. */
class CollectionTableFormController {
    constructor(root) {
        this.root = root;
        this.body = root.querySelector('[data-ctf-body]');
        this.template = root.querySelector('template[data-ctf-template]');
        this.empty = root.querySelector('[data-ctf-empty]');
        if (!this.body) return;
        this.namePrefix = `${root.dataset.ctfName}[`;
        this.idPrefix = `${root.dataset.ctfId}_`;
        this.nextIndex = Math.max(0, ...Array.from(this.rows(), row => {
            const name = row.querySelector('[name]')?.name || '';
            const match = name.startsWith(this.namePrefix) ? name.slice(this.namePrefix.length).match(/^(\d+)\]/) : null;
            return match ? Number(match[1]) + 1 : 0;
        }));
        root.addEventListener('click', event => this.onClick(event));
        if (this.body.querySelector('.ctf-drag-handle') || this.template?.content.querySelector('.ctf-drag-handle')) {
            Sortable.create(this.body, {
                handle: '.ctf-drag-handle', animation: 150, forceFallback: true,
                onEnd: () => this.reindex('ctf:row-moved'),
            });
        }
        this.update();
    }

    rows() { return this.body.querySelectorAll(':scope > [data-ctf-row]'); }

    onClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !this.root.contains(button)) return;
        const row = button.closest('[data-ctf-row]');
        switch (button.dataset.action) {
            case 'ctf-add-row': event.preventDefault(); this.addRow(); break;
            case 'ctf-remove-row': event.preventDefault(); if (row) { row.remove(); this.reindex('ctf:row-removed'); } break;
            case 'ctf-move-row-up':
            case 'ctf-move-row-down': {
                event.preventDefault();
                if (!row) break;
                const sibling = button.dataset.action.endsWith('up') ? row.previousElementSibling : row.nextElementSibling;
                if (!sibling) break;
                if (button.dataset.action.endsWith('up')) this.body.insertBefore(row, sibling);
                else this.body.insertBefore(sibling, row);
                this.reindex('ctf:row-moved', row);
                break;
            }
            case 'ctf-increment-quantity':
            case 'ctf-decrement-quantity': {
                event.preventDefault();
                const input = row?.querySelector('input[name$="[quantity]"]');
                if (!input) break;
                const delta = button.dataset.action === 'ctf-increment-quantity' ? 1 : -1;
                input.value = String(Math.min(Number(input.max || Infinity), Math.max(Number(input.min || 1), Number(input.value || 0) + delta)));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    addRow() {
        if (!this.template) return;
        const index = this.nextIndex++;
        const stage = document.createElement('tbody');
        stage.innerHTML = this.template.innerHTML.replaceAll('__name__', String(index));
        const row = stage.querySelector('[data-ctf-row]');
        if (!row) return;
        row.dataset.ctfIndex = String(index);
        this.body.appendChild(row);
        initCustomSelect(row);
        initTagsInput(row);
        window.PhoneInput?.initAll();
        this.update();
        this.root.dispatchEvent(new CustomEvent('ctf:row-added', { bubbles: true, detail: { row, index } }));
    }

    reindex(eventName, row = null) {
        const rows = this.rows();
        rows.forEach((entry, index) => {
            const firstName = entry.querySelector('[name]')?.name || '';
            const oldIndex = Number(entry.dataset.ctfIndex ?? (firstName.startsWith(this.namePrefix) ? firstName.slice(this.namePrefix.length).match(/^(\d+)\]/)?.[1] : NaN));
            if (!Number.isInteger(oldIndex)) return;
            entry.dataset.ctfIndex = String(index);
            entry.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(`${this.namePrefix}${oldIndex}]`, `${this.namePrefix}${index}]`); });
            entry.querySelectorAll('[id]').forEach(el => { el.id = el.id.replace(`${this.idPrefix}${oldIndex}_`, `${this.idPrefix}${index}_`); });
            entry.querySelectorAll('[for]').forEach(el => { el.htmlFor = el.htmlFor.replace(`${this.idPrefix}${oldIndex}_`, `${this.idPrefix}${index}_`); });
        });
        this.nextIndex = rows.length;
        this.update();
        this.root.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail: { row, rows } }));
    }

    update() {
        const rows = this.rows();
        this.empty?.classList.toggle('hidden', rows.length > 0);
        rows.forEach((row, index) => {
            const firstName = row.querySelector('[name]')?.name || '';
            const actualIndex = firstName.startsWith(this.namePrefix) ? firstName.slice(this.namePrefix.length).match(/^(\d+)\]/)?.[1] : null;
            row.dataset.ctfIndex = row.dataset.ctfIndex ?? actualIndex ?? String(index);
            const up = row.querySelector('[data-action="ctf-move-row-up"]');
            const down = row.querySelector('[data-action="ctf-move-row-down"]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === rows.length - 1;
        });
    }
}

function init() {
    document.querySelectorAll('[data-controller="collection-table-form"]').forEach(root => {
        if (!root.__collectionTableFormController) root.__collectionTableFormController = new CollectionTableFormController(root);
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
export { CollectionTableFormController, init };
