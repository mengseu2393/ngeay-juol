/**
 * The /m mobile shell's client side. Loaded once from <head> (a module, so it
 * runs before Livewire starts Alpine and before Livewire registers its own
 * popstate handler — the order the sheet controller below relies on). The
 * <head> survives wire:navigate, so nothing here is registered twice.
 */

// Per-area screen code (each registers its Alpine.data() on alpine:init).
import './mobile/rooms.js';
import './mobile/tenants.js';
import './mobile/billing.js';
import './mobile/settings.js';
import './mobile/property.js';

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

// A sheet is identified by its name, not by `this`: Alpine calls a method from an
// x-on expression through a fresh merged-scope proxy each time, so the `this` a
// sheet saw when it opened is never === the `this` it sees when it closes.
function isOpen(sheet) {
    return openSheet !== null && openSheet.name === sheet.name;
}

// A reload while a sheet was open lands on the sheet's history entry with no sheet
// on screen; make it an ordinary page entry so Back isn't a dead press.
if (history.state && history.state.rwSheet) {
    history.replaceState(null, '');
}

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

            if (openSheet && ! isOpen(this)) {
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

            if (isOpen(this) && history.state && history.state.rwSheet === this.name) {
                history.back(); // the popstate handler above calls closeNow()

                return;
            }

            this.closeNow();
        },

        closeNow() {
            if (isOpen(this)) {
                openSheet = null;
            }

            this.open = false;
            lockScroll(false);
            this.$dispatch('rw-m-sheet-closed', { name: this.name });

            // A drag-to-dismiss leaves the panel translated; clear it once the leave transition is over.
            const panel = this.$refs.panel;
            setTimeout(() => {
                panel.style.transform = '';
                panel.style.transition = '';
            }, 250);
        },

        // ── Drag down to dismiss: from the handle, or anywhere while the sheet is scrolled to its top ──
        drag: null,

        dragStart(event) {
            const panel = this.$refs.panel;

            if (event.touches.length !== 1 || panel.scrollTop > 0) {
                this.drag = null;

                return;
            }

            this.drag = { startY: event.touches[0].clientY, startedAt: Date.now(), dy: 0 };
            panel.style.transition = 'none';
        },

        dragMove(event) {
            if (! this.drag) {
                return;
            }

            const dy = event.touches[0].clientY - this.drag.startY;

            if (dy <= 0) {
                // Moving up: let the sheet scroll normally.
                this.drag.dy = 0;
                this.$refs.panel.style.transform = '';

                return;
            }

            event.preventDefault();
            this.drag.dy = dy;
            this.$refs.panel.style.transform = `translateY(${dy}px)`;
        },

        dragEnd() {
            if (! this.drag) {
                return;
            }

            const { dy, startedAt } = this.drag;
            const panel = this.$refs.panel;
            const flick = dy / Math.max(1, Date.now() - startedAt) > 0.5;
            this.drag = null;
            panel.style.transition = 'transform 160ms ease-out';

            if (dy > 120 || (flick && dy > 40)) {
                panel.style.transform = 'translateY(100%)';
                setTimeout(() => this.hide(), 140);
            } else {
                panel.style.transform = '';
            }
        },

        destroy() {
            if (isOpen(this)) {
                openSheet = null;
                lockScroll(false);
            }
        },
    }));
});

// ── Invoices screen (livewire.mobile.invoices) ───────────────────────────────
//
// Tap a card → the invoice in a sheet; "Record payment" → the payment sheet.
// The slip is a server-rendered fragment (route invoices.slip). It is fetched
// the moment a finger touches the card, so it is usually there by the time the
// tap lands, and kept until a payment changes it. Unlike the old Simple Mode
// screen, nothing is prefetched for cards nobody touches: that is mobile data.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileInvoices', (cashMethod, failedMessage) => ({
        invoice: null,
        slipLoading: false,
        slips: {},
        inflight: {},
        pay: { id: null, room: '', tenant: '', balance: '', amount: '', method: String(cashMethod), note: '' },

        init() {
            this.$wire.on('pay-saved', () => {
                this.slips = {};
                this.$dispatch('rw-m-sheet-close', { name: 'pay' });
                // The success message arrives with the same response; read it once Livewire applied it.
                requestAnimationFrame(() => this.$wire.paySuccessMessage && window.rwMobileToast(this.$wire.paySuccessMessage));
            });
        },

        fetchSlip(card) {
            if (this.slips[card.id]) {
                return Promise.resolve(this.slips[card.id]);
            }

            this.inflight[card.id] ??= fetch(card.slipUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                })
                .then((html) => (this.slips[card.id] = html))
                .finally(() => delete this.inflight[card.id]);

            return this.inflight[card.id];
        },

        prefetch(card) {
            this.fetchSlip(card).catch(() => {});
        },

        openInvoice(card) {
            this.invoice = card;
            this.$dispatch('rw-m-sheet-open', { name: 'invoice' });

            const body = document.getElementById('rw-m-slip');
            body.innerHTML = this.slips[card.id] || '';
            this.slipLoading = ! this.slips[card.id];

            if (! this.slipLoading) {
                return;
            }

            this.fetchSlip(card)
                .then((html) => {
                    if (this.invoice?.id === card.id) {
                        body.innerHTML = html;
                    }
                })
                .catch(() => {
                    this.$dispatch('rw-m-sheet-close', { name: 'invoice' });
                    window.rwMobileToast(failedMessage);
                })
                .finally(() => (this.slipLoading = false));
        },

        openPay(card) {
            this.pay = {
                id: card.id,
                room: card.room,
                tenant: card.tenant,
                balance: card.balanceLabel,
                amount: card.amount,
                method: String(cashMethod),
                note: '',
            };
            this.$dispatch('rw-m-sheet-open', { name: 'pay' });
        },

        savePay() {
            this.$wire.submitPayFor(this.pay.id, String(this.pay.amount), Number(this.pay.method), this.pay.note);
        },
    }));
});

// ── Pull to refresh ──────────────────────────────────────────────────────────
//
// The installed app has no browser reload, so a pull from the top of a screen
// re-renders that screen's Livewire component in place: fresh data, no page
// load, no history entry. The browser's own pull-to-refresh is switched off in
// mobile.css (overscroll-behavior) so the two never fire together.

(() => {
    const THRESHOLD = 70;
    const MAX = 110;
    let startY = null;
    let pull = 0;
    let refreshing = false;

    const indicator = () => document.getElementById('rw-m-ptr');

    const reset = (el) => {
        el.classList.remove('is-refreshing');
        el.style.transform = '';
        el.style.opacity = '0';
    };

    document.addEventListener('touchstart', (event) => {
        startY = null;

        if (refreshing || openSheet || window.scrollY > 0 || event.touches.length !== 1) {
            return;
        }

        if (event.target.closest('input, textarea, select, [data-no-ptr]')) {
            return;
        }

        startY = event.touches[0].clientY;
        pull = 0;
    }, { passive: true });

    document.addEventListener('touchmove', (event) => {
        const el = indicator();

        if (startY === null || ! el) {
            return;
        }

        pull = Math.min(MAX, Math.max(0, (event.touches[0].clientY - startY) * 0.5));
        el.style.transition = 'none';
        el.style.opacity = String(Math.min(1, pull / THRESHOLD));
        el.style.transform = `translate(-50%, ${pull}px) rotate(${pull * 3}deg)`;
    }, { passive: true });

    document.addEventListener('touchend', async () => {
        const el = indicator();

        if (startY === null || ! el) {
            return;
        }

        startY = null;
        el.style.transition = 'transform 200ms ease, opacity 200ms ease';

        if (pull < THRESHOLD) {
            reset(el);

            return;
        }

        refreshing = true;
        el.classList.add('is-refreshing');
        el.style.transform = `translate(-50%, ${THRESHOLD * 0.6}px)`;
        navigator.vibrate?.(10);

        try {
            const root = document.querySelector('main [wire\\:id]');

            if (root) {
                await window.Livewire.find(root.getAttribute('wire:id')).$refresh();
            }
        } finally {
            refreshing = false;
            reset(el);
        }
    });
})();

// ── Screen transitions ───────────────────────────────────────────────────────
// <main> is replaced on every wire:navigate (only the bars persist); the new one
// fades and rises in, so moving between screens doesn't read as a page load.

let firstLoad = true;

document.addEventListener('livewire:navigated', () => {
    if (firstLoad) {
        firstLoad = false;

        return;
    }

    document.querySelector('main')?.classList.add('rw-m-enter');
});

// ── Install prompt (mobile.partials.install-prompt) ──────────────────────────

const INSTALL_DISMISSED = 'rw-m-install-dismissed';
let deferredInstall = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault(); // we show our own, translated card instead of Chrome's mini-bar
    deferredInstall = event;
    window.dispatchEvent(new CustomEvent('rw-m-installable'));
});

window.addEventListener('appinstalled', () => {
    deferredInstall = null;
    window.dispatchEvent(new CustomEvent('rw-m-installed'));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileInstall', () => ({
        visible: false,
        ios: /iPhone|iPad|iPod/.test(navigator.userAgent),

        init() {
            const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            let dismissed = false;

            try {
                dismissed = localStorage.getItem(INSTALL_DISMISSED) === '1';
            } catch (e) {}

            if (standalone || dismissed) {
                return;
            }

            this.visible = this.ios || deferredInstall !== null;
            window.addEventListener('rw-m-installable', () => (this.visible = true));
            window.addEventListener('rw-m-installed', () => (this.visible = false));
        },

        async install() {
            if (! deferredInstall) {
                return;
            }

            deferredInstall.prompt();
            const { outcome } = await deferredInstall.userChoice;
            deferredInstall = null;

            if (outcome === 'accepted') {
                this.visible = false;
            }
        },

        dismiss() {
            this.visible = false;

            try {
                localStorage.setItem(INSTALL_DISMISSED, '1');
            } catch (e) {}
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
