/**
 * The /m mobile shell's client side. Loaded once from <head> (a module, so it
 * runs before Livewire starts Alpine and before Livewire registers its own
 * popstate handler — the order the sheet controller below relies on). The
 * <head> survives wire:navigate, so nothing here is registered twice.
 */

// ── Bottom sheets ────────────────────────────────────────────────────────────
//
// A sheet gets its own history entry, so the hardware back button (Android) and
// the edge swipe (iOS) close the sheet instead of leaving the screen — the way a
// native app behaves.
//
// The catch is Livewire's SPA navigation: on popstate it restores the page from
// its snapshot cache whenever the entry carries Livewire state, which the page
// entry under a sheet always does. Going "back" from a sheet would then swap the
// live page for the stale HTML it had when it was first opened. So a back that
// only closes a sheet is stopped here, before Livewire sees it.

let openSheet = null;

window.addEventListener('popstate', (event) => {
    if (! openSheet || (event.state && event.state.rwSheet)) {
        return;
    }

    event.stopImmediatePropagation();

    const sheet = openSheet;
    openSheet = null;
    sheet.closeNow();
}, true);

// Leaving the page with a sheet open (a link inside it): put the page's own
// history state back first, so Livewire stores its snapshot on a normal entry.
document.addEventListener('livewire:navigate', () => {
    if (! openSheet) {
        return;
    }

    const sheet = openSheet;
    openSheet = null;
    history.replaceState(sheet.pageState, '');
    sheet.closeNow();
});

function lockScroll(locked) {
    document.documentElement.style.overflow = locked ? 'hidden' : '';
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileSheet', (name) => ({
        name,
        open: false,
        pageState: null,

        show() {
            if (this.open) {
                return;
            }

            if (openSheet && openSheet !== this) {
                // One sheet at a time: the new one takes over the old one's history entry.
                this.pageState = openSheet.pageState;
                openSheet.closeNow();
                history.replaceState({ rwSheet: this.name }, '');
            } else {
                this.pageState = history.state;
                history.pushState({ rwSheet: this.name }, '');
            }

            openSheet = this;
            this.open = true;
            lockScroll(true);
            this.$dispatch('rw-m-sheet-opened', { name: this.name });
        },

        /** User closed it (button, backdrop, Escape): step back off the sheet's history entry. */
        hide() {
            if (! this.open) {
                return;
            }

            if (openSheet === this && history.state && history.state.rwSheet === this.name) {
                history.back(); // the popstate handler above calls closeNow()

                return;
            }

            this.closeNow();
        },

        closeNow() {
            if (openSheet === this) {
                openSheet = null;
            }

            this.open = false;
            lockScroll(false);
            this.$dispatch('rw-m-sheet-closed', { name: this.name });
        },

        destroy() {
            if (openSheet === this) {
                openSheet = null;
                lockScroll(false);
            }
        },
    }));
});

// ── Toast ────────────────────────────────────────────────────────────────────

window.rwMobileToast = function (text) {
    document.getElementById('rw-m-toast')?.remove();

    const el = document.createElement('div');
    el.id = 'rw-m-toast';
    el.className = 'rw-m-toast';
    el.setAttribute('role', 'status');
    el.textContent = text;
    el.addEventListener('click', () => el.remove());
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
};

window.addEventListener('rw-m-toast', (event) => window.rwMobileToast(event.detail.message));

// ── Tab bar ──────────────────────────────────────────────────────────────────
// The bar is @persist'ed across wire:navigate, so its server-rendered active tab
// goes stale; re-mark it from the URL after every navigation.

function markActiveTab() {
    document.querySelectorAll('[data-rw-m-tab]').forEach((tab) => {
        if (new RegExp(tab.dataset.match).test(location.pathname)) {
            tab.setAttribute('aria-current', 'page');
        } else {
            tab.removeAttribute('aria-current');
        }
    });
}

document.addEventListener('livewire:navigated', markActiveTab);

// ── Property switch: a full reload, so no screen and no cached back-snapshot
//    keeps showing the previous property's data. ──
window.addEventListener('rw-m-property-switched', () => window.location.reload());

// ── Service worker (same one as the rest of the app) ──
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
