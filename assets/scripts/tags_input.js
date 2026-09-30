class TagsInputController {
    constructor(root) {
        this.root = root;
        this.carrier = root.querySelector('[data-tags-input-target="csv"]');
        this.chips = root.querySelector('[data-tags-input-target="chips"]');
        this.entry = root.querySelector('[data-tags-input-target="entry"]');
        if (!this.carrier || !this.chips || !this.entry) return;
        this.tags = this.parse(this.carrier.value);
        this.entry.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ',') { event.preventDefault(); this.commit(); }
            else if (event.key === 'Backspace' && !this.entry.value && this.tags.length) {
                this.tags.pop(); this.sync();
            }
        });
        this.entry.addEventListener('blur', () => this.commit());
        this.carrier.addEventListener('change', () => { this.tags = this.parse(this.carrier.value); this.render(); });
        this.entry.closest('form')?.addEventListener('submit', () => this.commit());
        this.render();
    }

    parse(value) { return value.split('|').map(tag => tag.trim()).filter(Boolean); }
    commit() {
        const tag = this.entry.value.trim().replace(/[|,]/g, '');
        if (!tag) return;
        if (!this.tags.includes(tag)) this.tags.push(tag);
        this.entry.value = '';
        this.sync();
    }
    sync() {
        this.carrier.value = this.tags.join('|');
        this.carrier.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }
    render() {
        this.chips.replaceChildren();
        this.tags.forEach((tag, index) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-700';
            const label = document.createElement('span'); label.textContent = tag;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'rounded-full px-1 text-stone-500 hover:text-rose-700 focus:outline-none focus:ring-1 focus:ring-rose-500';
            remove.setAttribute('aria-label', `${this.root.dataset.removeLabel || 'Remove tag'}: ${tag}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => { this.tags.splice(index, 1); this.sync(); this.entry.focus(); });
            chip.append(label, remove); this.chips.appendChild(chip);
        });
    }
}
function initWithin(scope = document) {
    scope.querySelectorAll('[data-tags-input]').forEach(root => {
        if (!root.__tagsInput) root.__tagsInput = new TagsInputController(root);
    });
}
const init = () => initWithin();
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
export { TagsInputController, init, initWithin };
