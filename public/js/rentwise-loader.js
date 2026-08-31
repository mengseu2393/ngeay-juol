/**
 * RentWise — the global loading indicator.
 *
 * Toggles `rw-loading` (and `rw-loading--nav`) on <html>; public/css/rentwise-loader.css paints
 * the overlay from there. The state lives on <html> rather than in the body because Livewire's
 * SPA navigation swaps the entire <body> mid-flight, and a loader that gets swapped away is a
 * loader you can never take down again.
 *
 * Loaded from <head> on every page — early enough that the overlay is part of the first paint,
 * and (in the Filament panels) in the one part of the document Livewire keeps across SPA
 * navigations, so the listeners below are registered exactly once.
 */
(function () {
    'use strict';

    if (window.rwLoader) {
        return;
    }

    var root = document.documentElement;
    var VISIBLE = 'rw-loading';
    var NAV = 'rw-loading--nav';

    /* A navigation that lands inside this window never gets a spinner — it would only flash. */
    var NAV_DELAY = 140;

    /* Nothing may pin the overlay open forever: a stuck loader reads as a dead app. */
    var BOOT_TIMEOUT = 10000;
    var NAV_TIMEOUT = 15000;

    var showTimer = null;
    var safetyTimer = null;

    function clearTimers() {
        clearTimeout(showTimer);
        clearTimeout(safetyTimer);
        showTimer = null;
        safetyTimer = null;
    }

    function hide() {
        clearTimers();

        // Guarded: several of the listeners below fire on an already-clean page, and every
        // write to <html>'s class attribute wakes Alpine's and Livewire's DOM observers.
        if (root.classList.contains(VISIBLE) || root.classList.contains(NAV)) {
            root.classList.remove(VISIBLE, NAV);
        }
    }

    /**
     * @param {{nav?: boolean, delay?: number, timeout?: number}} [options]
     *        nav — translucent backdrop over the page you are leaving, instead of an opaque one.
     */
    function show(options) {
        var settings = options || {};
        var nav = settings.nav !== false;

        clearTimers();

        var paint = function () {
            showTimer = null;
            root.classList.add(VISIBLE);
            root.classList.toggle(NAV, nav);
        };

        if (settings.delay) {
            showTimer = setTimeout(paint, settings.delay);
        } else {
            paint();
        }

        safetyTimer = setTimeout(hide, settings.timeout || NAV_TIMEOUT);
    }

    // ── First paint ─────────────────────────────────────────────────
    // Up before the document below it exists, down as soon as the page is usable.
    show({ nav: false, timeout: BOOT_TIMEOUT });

    if (document.readyState === 'complete') {
        hide();
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            // One frame, so the page underneath has painted before the cover comes off.
            window.requestAnimationFrame(hide);
        });
        window.addEventListener('load', hide);
    }

    // ── Livewire SPA navigation (both Filament panels run ->spa()) ──
    document.addEventListener('livewire:navigate', function () {
        show({ delay: NAV_DELAY });
    });
    document.addEventListener('livewire:navigated', hide);

    // ── Classic navigation: links, form submits, redirects ──────────
    // `beforeunload` never fires for a download — that navigation is cancelled before it commits
    // — so the invoice PDF and Excel links can't strand the overlay.
    window.addEventListener('beforeunload', function () {
        show({ delay: NAV_DELAY });
    });

    // Back/forward out of the bfcache restores a page exactly as it was left: mid-navigation,
    // overlay up. Every restored page starts clean.
    window.addEventListener('pageshow', hide);

    // Escape hatches for navigations that never happen — an unsaved-changes prompt the user
    // cancels, a link that turned out to be a download. Any sign of life clears the overlay.
    window.addEventListener('focus', hide);
    document.addEventListener('pointerdown', hide, true);
    document.addEventListener('keydown', hide, true);

    window.rwLoader = { show: show, hide: hide };
})();
