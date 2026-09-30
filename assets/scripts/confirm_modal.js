const dialog = document.querySelector('#app-confirm-modal');

if (dialog instanceof HTMLDialogElement) {
    const title = dialog.querySelector('[data-confirm-title]');
    const message = dialog.querySelector('[data-confirm-message]');
    const cancel = dialog.querySelector('[data-confirm-cancel]');
    const submit = dialog.querySelector('[data-confirm-submit]');
    let pendingForm = null;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-confirm-form]');
        if (!trigger) return;

        const form = document.getElementById(trigger.dataset.confirmForm);
        if (!(form instanceof HTMLFormElement)) return;

        event.preventDefault();
        pendingForm = form;
        title.textContent = trigger.dataset.confirmTitle || '';
        message.textContent = trigger.dataset.confirmMessage || '';
        dialog.showModal();
        cancel.focus();
    });

    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        pendingForm = null;
    });
    submit.addEventListener('click', () => {
        const form = pendingForm;
        pendingForm = null;
        dialog.close();
        form?.requestSubmit();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
}
