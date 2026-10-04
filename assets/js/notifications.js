const bell = document.querySelector('[data-notification-bell]');
if (bell) {
    const toggle = bell.querySelector('[data-notification-toggle]');
    const panel = bell.querySelector('[data-notification-panel]');
    const items = bell.querySelector('[data-notification-items]');
    const count = bell.querySelector('[data-notification-count]');
    let loading = false;
    let stopped = false;
    const close = () => { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
    const refresh = async () => {
        if (loading || stopped || document.hidden) return;
        loading = true;
        try {
            const response = await fetch(bell.dataset.feed, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (response.redirected || response.status === 401 || response.status === 403) { stopped = true; close(); return; }
            if (!response.ok) throw new Error();
            const data = await response.json();
            count.textContent = data.unreadCount > 99 ? '99+' : data.unreadCount;
            count.hidden = !data.unreadCount;
            count.classList.toggle('hidden', !data.unreadCount);
            items.replaceChildren();
            for (const item of data.items) {
                const link = document.createElement('a');
                link.href = item.url;
                link.className = 'block border-b border-stone-100 px-4 py-3 hover:bg-stone-50';
                const title = document.createElement('strong');
                title.className = item.unread ? 'text-sm text-amber-800' : 'text-sm text-stone-800';
                title.textContent = item.title;
                const body = document.createElement('p');
                body.className = 'mt-1 text-xs text-stone-600';
                body.textContent = item.body;
                const time = document.createElement('time');
                time.className = 'mt-1 block text-xs text-stone-400';
                time.dateTime = item.createdAt;
                time.textContent = new Date(item.createdAt).toLocaleString(document.documentElement.lang);
                link.append(title, body, time);
                link.addEventListener('click', async (event) => {
                    if (!item.unread || event.ctrlKey || event.metaKey || event.shiftKey) return;
                    event.preventDefault();
                    try {
                        await fetch(bell.dataset.readUrl.replace('/0/read', `/${item.id}/read`), {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-Token': bell.dataset.token}});
                    } finally { window.location.assign(link.href); }
                });
                items.append(link);
            }
            if (!data.items.length) {
                const empty = document.createElement('p');
                empty.className = 'px-4 py-8 text-center text-sm text-stone-500';
                empty.textContent = bell.dataset.empty;
                items.append(empty);
            }
        } catch {
            if (!panel.hidden) items.textContent = bell.dataset.error;
        } finally { loading = false; }
    };
    toggle.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) refresh();
    });
    document.addEventListener('click', (event) => { if (!bell.contains(event.target)) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) { close(); toggle.focus(); } });
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('focus', refresh);
    navigator.serviceWorker?.addEventListener('message', (event) => { if (event.data?.type === 'notification') refresh(); });
    setInterval(refresh, 30000);
    refresh();
}

const settings = document.querySelector('[data-push-settings]');
if (settings?.dataset.key && settings.querySelector('[data-push-enable]')) {
    const enable = settings.querySelector('[data-push-enable]');
    const disable = settings.querySelector('[data-push-disable]');
    const message = settings.querySelector('[data-push-message]');
    const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    let registration;
    let subscriptionId = null;
    const hash = async (value) => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))), (b) => b.toString(16).padStart(2, '0')).join('');
    const post = async (url, data) => {
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': settings.dataset.token}, body: JSON.stringify(data)});
        if (!response.ok || response.redirected) throw new Error();
        return response.json();
    };
    if (!supported) {
        message.textContent = settings.dataset.unsupported;
    } else {
        const initialize = async () => {
            registration = await navigator.serviceWorker.register('/sw.js', {scope: '/', updateViaCache: 'none'});
            await navigator.serviceWorker.ready;
            const existing = await registration.pushManager.getSubscription();
            if (existing) {
                const response = await fetch(`${settings.dataset.status}?endpointHash=${await hash(existing.endpoint)}`, {cache: 'no-store'});
                if (!response.ok || response.redirected) throw new Error();
                subscriptionId = (await response.json()).id;
                // A revoked/logout/other-account browser subscription must not be
                // silently rebound. Recreate it only after an explicit enable click.
            }
            enable.disabled = false;
            disable.disabled = !subscriptionId;
            message.textContent = subscriptionId ? settings.dataset.enabled : settings.dataset.disabled;
        };
        initialize().catch(() => { message.textContent = settings.dataset.error; });
        enable.addEventListener('click', async () => {
            // Call directly in the user gesture; Chrome may reject delayed prompts.
            const permission = Notification.requestPermission();
            enable.disabled = true;
            try {
                if (await permission !== 'granted') { message.textContent = settings.dataset.denied; return; }
                let subscription = await registration.pushManager.getSubscription();
                if (subscription && !subscriptionId) { await subscription.unsubscribe(); subscription = null; }
                const base64 = settings.dataset.key.replace(/-/g, '+').replace(/_/g, '/');
                const key = Uint8Array.from(atob(base64 + '='.repeat((4 - base64.length % 4) % 4)), (c) => c.charCodeAt(0));
                subscription ??= await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: key});
                const data = subscription.toJSON();
                data.deviceLabel = /Android/i.test(navigator.userAgent) ? 'Chrome · Android' : 'Chrome · Desktop';
                subscriptionId = (await post(settings.dataset.register, data)).id;
                window.location.reload();
            } catch { message.textContent = settings.dataset.error; }
            finally { enable.disabled = false; }
        });
        disable.addEventListener('click', async () => {
            disable.disabled = true;
            try {
                if (subscriptionId) await post(settings.dataset.disable.replace('/0/disable', `/${subscriptionId}/disable`), {});
                await (await registration.pushManager.getSubscription())?.unsubscribe();
                window.location.reload();
            } catch { message.textContent = settings.dataset.error; disable.disabled = false; }
        });
    }
}
