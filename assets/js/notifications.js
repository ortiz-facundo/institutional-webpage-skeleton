// Panel icon per notification type (Heroicons 24/outline `d=` strings).
// Lookup order: exact type ('dev.users'), then its module prefix ('dev'),
// then the bell. Downstreams register their own types here.
const TYPE_ICONS = {
    'dev.test': 'check-circle',
    'dev.users': 'users',
};
const ICON_PATHS = {
    bell: 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75v-.7a6 6 0 0 0-12 0v.7a8.967 8.967 0 0 1-2.311 6.022 23.848 23.848 0 0 0 5.454 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
    'check-circle': 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    users: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    shield: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
    'exclamation-triangle': 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
    'arrow-down-tray': 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
};
const typeIcon = (type = '') => ICON_PATHS[TYPE_ICONS[type] ?? TYPE_ICONS[type.split('.')[0]]] ?? ICON_PATHS.bell;

// The backoffice renders two bells (sidebar footer at `lg`+, mobile top bar
// below it). One poll feeds both; each bell owns its own panel.
const bells = Array.from(document.querySelectorAll('[data-notification-bell]'));
if (bells.length) {
    const config = bells[0].dataset;
    const lang = document.documentElement.lang || undefined;
    let data = null;
    let failed = false;
    let loading = false;
    let stopped = false;
    const parts = (bell) => ({
        toggle: bell.querySelector('[data-notification-toggle]'),
        panel: bell.querySelector('[data-notification-panel]'),
        items: bell.querySelector('[data-notification-items]'),
        count: bell.querySelector('[data-notification-count]'),
        fresh: bell.querySelector('[data-notification-new]'),
        readAll: bell.querySelector('[data-notification-read-all]'),
    });
    const close = (bell) => { const {panel, toggle} = parts(bell); panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
    const post = async (url) => {
        const response = await fetch(url, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-Token': config.token}});
        if (!response.ok || response.redirected) throw new Error();
    };
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const relative = (date) => {
        const minutes = Math.round((Date.now() - date) / 60000);
        if (minutes < 1) return config.justNow;
        if (date.toDateString() !== new Date().toDateString()) {
            return date.toLocaleString(lang, {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'});
        }
        const format = new Intl.RelativeTimeFormat(lang, {numeric: 'auto'});
        return minutes < 60 ? format.format(-minutes, 'minute') : format.format(-Math.floor(minutes / 60), 'hour');
    };
    const markRead = (item) => {
        if (!item.unread) return;
        item.unread = false;
        data.unreadCount = Math.max(0, data.unreadCount - 1);
    };
    const renderItem = (item) => {
        const row = el('div', `group relative flex gap-3 px-4 py-3 hover:bg-stone-50 ${item.unread ? 'bg-amber-50/60' : ''}`);
        const badge = el('span', `mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${item.unread ? 'bg-amber-100 text-amber-800' : 'bg-stone-100 text-stone-500'}`);
        badge.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${typeIcon(item.type)}"/></svg>`;
        row.append(badge);
        const text = el('div', 'min-w-0 flex-1');
        // Stretched link: the whole row is clickable, the mark-read button sits above it.
        const link = el('a', `block text-sm leading-snug after:absolute after:inset-0 focus:outline-none focus-visible:underline ${item.unread ? 'font-semibold text-stone-900' : 'text-stone-700'}`, item.title);
        link.href = item.url;
        link.addEventListener('click', async (event) => {
            if (!item.unread || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            try { await post(config.readUrl.replace('/0/read', `/${item.id}/read`)); } catch { /* navigate anyway */ }
            window.location.assign(link.href);
        });
        const date = new Date(item.createdAt);
        const time = el('time', 'mt-1 block text-[11px] text-stone-400', relative(date));
        time.dateTime = item.createdAt;
        time.title = date.toLocaleString(lang);
        text.append(link, el('p', 'mt-0.5 line-clamp-2 text-xs text-stone-500', item.body), time);
        row.append(text);
        if (item.unread) {
            const side = el('div', 'relative z-10 flex w-6 shrink-0 flex-col items-center gap-1.5');
            const button = el('button', 'inline-flex h-6 w-6 items-center justify-center rounded text-stone-400 hover:bg-white hover:text-amber-700 focus:opacity-100 focus:outline-none focus:ring-2 focus:ring-amber-600 group-hover:opacity-100 [@media(hover:hover)]:opacity-0');
            button.type = 'button';
            button.title = config.markRead;
            button.setAttribute('aria-label', config.markRead);
            button.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>';
            button.addEventListener('click', async (event) => {
                event.stopPropagation();
                button.disabled = true;
                try { await post(config.readUrl.replace('/0/read', `/${item.id}/read`)); markRead(item); render(); } catch { button.disabled = false; }
            });
            side.append(el('span', 'mt-1.5 h-2 w-2 rounded-full bg-amber-600'), button);
            row.append(side);
        }
        return row;
    };
    const renderList = (items) => {
        if (failed && !data) { items.replaceChildren(el('p', 'px-4 py-8 text-center text-sm text-stone-500', config.error)); return; }
        if (!data) return;
        if (!data.items.length) { items.replaceChildren(el('p', 'px-4 py-10 text-center text-sm text-stone-500', config.empty)); return; }
        const today = new Date().toDateString();
        const nodes = [];
        for (const [label, inGroup] of [[config.today, true], [config.earlier, false]]) {
            const group = data.items.filter((item) => (new Date(item.createdAt).toDateString() === today) === inGroup);
            if (!group.length) continue;
            nodes.push(el('p', 'px-4 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-stone-400', label), ...group.map(renderItem));
        }
        items.replaceChildren(...nodes);
    };
    const render = () => {
        const unread = data?.unreadCount ?? 0;
        for (const bell of bells) {
            const {count, fresh, readAll, items} = parts(bell);
            count.textContent = unread > 99 ? '99+' : unread;
            count.hidden = !unread;
            fresh.textContent = config.newCount.replace('__COUNT__', unread);
            fresh.hidden = !unread;
            readAll.disabled = !unread;
            renderList(items);
        }
    };
    // Background polls skip hidden tabs; opening the panel always loads.
    const refresh = async (force = false) => {
        if (loading || stopped || (document.hidden && !force)) return;
        loading = true;
        try {
            const response = await fetch(config.feed, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (response.redirected || response.status === 401 || response.status === 403) { stopped = true; bells.forEach(close); return; }
            if (!response.ok) throw new Error();
            data = await response.json();
            failed = false;
        } catch {
            failed = true;
        } finally {
            loading = false;
            render();
        }
    };
    for (const bell of bells) {
        const {toggle, panel, readAll} = parts(bell);
        toggle.addEventListener('click', () => {
            const opening = panel.hidden;
            bells.forEach(close);
            if (!opening) return;
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            refresh(true);
        });
        readAll.addEventListener('click', async () => {
            readAll.disabled = true;
            try {
                await post(config.readAllUrl);
                data?.items.forEach((item) => { item.unread = false; });
                if (data) data.unreadCount = 0;
                render();
            } catch { refresh(); }
        });
    }
    document.addEventListener('click', (event) => { bells.forEach((bell) => { if (!bell.contains(event.target)) close(bell); }); });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const open = bells.find((bell) => !parts(bell).panel.hidden);
        if (open) { close(open); parts(open).toggle.focus(); }
    });
    document.addEventListener('visibilitychange', () => refresh());
    window.addEventListener('focus', () => refresh());
    navigator.serviceWorker?.addEventListener('message', (event) => { if (event.data?.type === 'notification') refresh(); });
    setInterval(() => refresh(), 30000);
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
