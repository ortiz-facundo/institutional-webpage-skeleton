// Relative time for today's notifications, short date + time for older ones.
// Shared by the bell panel and the inbox page (`[data-notification-time]`).
const lang = document.documentElement.lang || undefined;
const relativeTime = (date, justNow) => {
    const minutes = Math.round((Date.now() - date) / 60000);
    if (minutes < 1) return justNow;
    if (date.toDateString() !== new Date().toDateString()) {
        return date.toLocaleString(lang, {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'});
    }
    const format = new Intl.RelativeTimeFormat(lang, {numeric: 'auto'});
    return minutes < 60 ? format.format(-minutes, 'minute') : format.format(-Math.floor(minutes / 60), 'hour');
};

const postNotificationAction = async (url, token) => {
    const response = await fetch(url, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-Token': token}});
    if (!response.ok || response.redirected) throw new Error();
};

// Both server-rendered inbox links and dynamically rendered popup links use
// this read-before-navigation behavior. GET destinations remain side-effect free.
document.addEventListener('click', async (event) => {
    const link = event.target.closest('a[data-notification-read-url]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    try {
        await postNotificationAction(link.dataset.notificationReadUrl, link.dataset.notificationReadToken);
    } catch { /* preserve the popup's navigate-anyway behavior on a failed POST */ }
    window.location.assign(link.href);
});

// The backoffice renders two bells (sidebar footer at `lg`+, mobile top bar
// below it). One poll feeds both; each bell owns its own panel.
const bells = Array.from(document.querySelectorAll('[data-notification-bell]'));
if (bells.length) {
    const config = bells[0].dataset;
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
    const post = (url) => postNotificationAction(url, config.token);
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const markRead = (item) => {
        if (!item.unread) return;
        item.unread = false;
        data.unreadCount = Math.max(0, data.unreadCount - 1);
    };
    const renderItem = (item) => {
        const row = el('div', `group relative flex gap-3 px-4 py-3 hover:bg-stone-50 ${item.unread ? 'bg-amber-50/60 shadow-[inset_3px_0_0_0_theme(colors.amber.600)]' : ''}`);
        const badge = el('span', `mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${item.unread ? 'bg-amber-100 text-amber-800' : 'bg-stone-100 text-stone-500'}`);
        badge.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${item.icon}"/></svg>`;
        row.append(badge);
        const text = el('div', 'min-w-0 flex-1');
        // Stretched link: the whole row is clickable, the mark-read button sits above it.
        const link = el('a', `block text-sm leading-snug after:absolute after:inset-0 focus:outline-none focus-visible:underline ${item.unread ? 'font-semibold text-stone-900' : 'text-stone-700'}`, item.title);
        link.href = item.url;
        if (item.unread) {
            link.dataset.notificationReadUrl = config.readUrl.replace('/0/read', `/${item.id}/read`);
            link.dataset.notificationReadToken = config.token;
        }
        const date = new Date(item.createdAt);
        const meta = el('div', 'mt-1 flex flex-wrap items-center gap-1.5');
        const time = el('time', 'text-[11px] text-stone-400', relativeTime(date, config.justNow));
        time.dateTime = item.createdAt;
        time.title = date.toLocaleString(lang);
        meta.append(el('span', 'rounded bg-stone-100 px-1.5 text-[10px] font-medium leading-4 text-stone-600', item.moduleLabel), time);
        text.append(link, el('p', 'mt-0.5 line-clamp-2 text-xs text-stone-500', item.body), meta);
        row.append(text);
        if (!item.archived) {
            const side = el('div', 'relative z-10 flex w-6 shrink-0 flex-col items-center gap-1.5');
            const button = el('button', 'inline-flex h-6 w-6 items-center justify-center rounded text-stone-400 hover:bg-white hover:text-amber-700 focus:opacity-100 focus:outline-none focus:ring-2 focus:ring-amber-600 group-hover:opacity-100 [@media(hover:hover)]:opacity-0');
            button.type = 'button';
            const action = item.unread ? 'read' : 'archive';
            const label = item.unread ? config.markRead : config.archive;
            button.title = label;
            button.setAttribute('aria-label', label);
            button.dataset.notificationAction = action;
            const icon = item.unread
                ? 'm4.5 12.75 6 6 9-13.5'
                : 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 3h4.5M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z';
            button.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${icon}"/></svg>`;
            button.addEventListener('click', async (event) => {
                event.stopPropagation();
                button.disabled = true;
                try {
                    const url = action === 'read'
                        ? config.readUrl.replace('/0/read', `/${item.id}/read`)
                        : config.archiveUrl.replace('/0/archive', `/${item.id}/archive`);
                    await post(url);
                    if (action === 'read') {
                        markRead(item);
                    } else {
                        data.items = data.items.filter((entry) => entry.id !== item.id);
                    }
                    render();
                    if (action === 'archive') await refresh(true);
                } catch { button.disabled = false; }
            });
            if (item.unread) side.append(el('span', 'mt-1.5 h-2 w-2 rounded-full bg-amber-600'));
            side.append(button);
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

// Inbox page: the server renders absolute dates; switch them to the panel's
// relative format (the exact time stays in the title tooltip).
const justNow = bells[0]?.dataset.justNow ?? '';
document.querySelectorAll('[data-notification-time]').forEach((time) => {
    time.textContent = relativeTime(new Date(time.dateTime), justNow);
});

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
