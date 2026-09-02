/**
 * Backoffice sidebar — off-canvas drawer on mobile, static at `lg`+.
 *
 * Generalized for every page that renders
 * `templates/backoffice/base.html.twig`. The sidebar is the
 * left-hand nav; below `lg` it lives off-screen (translated -100%
 * on X via a Tailwind utility on `<aside data-sidebar>`), a
 * backdrop overlay covers the rest of the page, and a hamburger
 * button in the mobile top bar opens it. At `lg` and up Tailwind's
 * responsive classes pin the sidebar back into the static
 * sticky-always-visible layout (`lg:sticky lg:translate-x-0`), so
 * this module's open/close state doesn't apply on those
 * viewports — the toggle/close buttons are `lg:hidden` and the
 * module no-ops gracefully when the sidebar isn't found.
 *
 * Markup contract (server-side renders these):
 *   - <aside data-sidebar>                       the drawer
 *   - <div   data-sidebar-backdrop>              the dimming overlay
 *   - <button data-sidebar-toggle>               the hamburger(s)
 *                                                that open the drawer
 *   - <button data-sidebar-close>                the X button(s)
 *                                                inside the drawer
 *
 * State is reflected entirely in the DOM (translate-x classes on
 * the sidebar + the backdrop's `hidden` class). The body gets
 * `overflow-hidden` while open so the page underneath doesn't
 * scroll behind the drawer. No persistence across reloads — the
 * drawer is closed on every fresh load.
 *
 * Pure vanilla IIFE — mirrors the convention used by every other
 * assets/js/*.js module.
 */

(function () {
    'use strict';

    const LG_BREAKPOINT_PX = 1024; // Tailwind's `lg`

    function getSidebar() {
        return document.querySelector('[data-sidebar]');
    }

    function getBackdrop() {
        return document.querySelector('[data-sidebar-backdrop]');
    }

    function getToggleButtons() {
        return Array.from(document.querySelectorAll('[data-sidebar-toggle]'));
    }

    function getCloseButtons() {
        return Array.from(document.querySelectorAll('[data-sidebar-close]'));
    }

    function isDesktopViewport() {
        return window.matchMedia(`(min-width: ${LG_BREAKPOINT_PX}px)`).matches;
    }

    function open() {
        const sidebar = getSidebar();
        const backdrop = getBackdrop();
        if (!sidebar) return;
        // Idempotent: only flip the classes if the drawer is
        // actually closed. Avoids a redundant class write when
        // a user double-taps the hamburger.
        if (sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
        }
        if (backdrop && backdrop.classList.contains('hidden')) {
            backdrop.classList.remove('hidden');
        }
        document.body.classList.add('overflow-hidden');
    }

    function close() {
        const sidebar = getSidebar();
        const backdrop = getBackdrop();
        if (!sidebar) return;
        if (sidebar.classList.contains('translate-x-0')) {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
        }
        if (backdrop && !backdrop.classList.contains('hidden')) {
            backdrop.classList.add('hidden');
        }
        document.body.classList.remove('overflow-hidden');
    }

    function bindToggleButtons() {
        getToggleButtons().forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                open();
            });
        });
    }

    function bindCloseButtons() {
        getCloseButtons().forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                close();
            });
        });
    }

    function bindBackdrop() {
        const backdrop = getBackdrop();
        if (!backdrop) return;
        backdrop.addEventListener('click', function () {
            close();
        });
    }

    function bindEscape() {
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            close();
        });
    }

    /**
     * If the viewport crosses into `lg`+ while the drawer is open,
     * close it. Tailwind's `lg:` utilities repaint the sidebar into
     * its static sticky layout, but the body's `overflow-hidden`
     * would still leak (the rest of the page would stop scrolling)
     * — `close()` strips it back out.
     */
    function bindResizeToClose() {
        window.addEventListener('resize', function () {
            if (isDesktopViewport()) {
                close();
            }
        });
    }

    function init() {
        // No-op when the page has no sidebar (defensive — mirrors
        // the same guard pattern used by every other assets/js
        // module, e.g. crud-bulk-actions.js's no-op when there are
        // no bulk checkboxes).
        if (!getSidebar()) return;
        bindToggleButtons();
        bindCloseButtons();
        bindBackdrop();
        bindEscape();
        bindResizeToClose();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
