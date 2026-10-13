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
