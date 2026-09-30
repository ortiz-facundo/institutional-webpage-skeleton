function init(root) {
    if (root.dataset.phoneInputBound === '1') return;
    const country = root.querySelector('[data-select-native]');
    const area = root.querySelector('[data-phone-area]');
    const number = root.querySelector('[data-phone-number]');
    const preview = root.querySelector('[data-phone-preview]');
    if (!country || !area || !number) return;
    root.dataset.phoneInputBound = '1';
    const sync = () => {
        const digits = area.value + number.value;
        const mobile = country.value === '54' && digits.length >= 10 && !digits.startsWith('9');
        if (preview) preview.textContent = digits ? `+${country.value} ${mobile ? '9 ' : ''}${area.value} ${number.value}` : '—';
    };
    country.addEventListener('change', sync);
    for (const field of [area, number]) field.addEventListener('input', () => {
        field.value = field.value.replace(/\D/g, '').slice(0, field.maxLength);
        sync();
    });
    area.addEventListener('input', () => { if (area.value.length === area.maxLength) number.focus(); });
    sync();
}
function initAll() { document.querySelectorAll('[data-phone-input]').forEach(init); }
window.PhoneInput = { init, initAll };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
else initAll();
export { init, initAll };
