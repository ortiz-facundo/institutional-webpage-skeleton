function init() {
    document.querySelectorAll('[data-controller="dismissible-flash"]').forEach((card) => {
        if (card.dataset.dismissibleFlashBound === '1') return;

        card.dataset.dismissibleFlashBound = '1';
        card.querySelector('[data-action="dismiss-flash"]')?.addEventListener('click', () => card.remove());
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
