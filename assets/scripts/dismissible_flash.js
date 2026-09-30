// Flash messages are ephemeral; contextual hints can opt into persistent
// dismissal using a stable data-hint-key (for example, feature + record id).
const prefix = 'dismissed-hint:';
function init() {
    document.querySelectorAll('[data-controller="dismissible-flash"], [data-controller="dismissible-hint"]').forEach(card => {
        if (card.dataset.dismissibleBound === '1') return;
        card.dataset.dismissibleBound = '1';
        const key = card.dataset.hintKey;
        if (key) {
            try { if (localStorage.getItem(prefix + key) === '1') { card.remove(); return; } }
            catch (_) { /* Storage can be unavailable in private browsing. */ }
        }
        card.querySelector('[data-action="dismiss-flash"], [data-action="dismiss-hint"]')?.addEventListener('click', () => {
            if (key) {
                try { localStorage.setItem(prefix + key, '1'); }
                catch (_) { /* The current card can still be dismissed. */ }
            }
            card.remove();
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { init };
