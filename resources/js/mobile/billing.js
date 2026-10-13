// /m billing screens — Alpine.data() registrations live here (see resources/js/mobile.js).

// ── Create invoices (livewire.mobile.billing) ────────────────────────────────
//
// The wizard is server-driven (App\Livewire\Mobile\Billing); this only makes the
// reading entry feel native:
//  - Enter ("next" on the keyboard) moves to the next reading field of the room,
//    synchronously, so the keyboard stays up; "done" on the last one closes it.
//  - Next room / Previous with the keyboard up: a phone only opens the keyboard
//    for a focus inside the tap itself, and the next room's fields only exist
//    once the server answered. So the tap parks focus on an invisible proxy
//    field (keyboard stays open), and once the new card is in, focus moves on
//    to its first empty reading.
//  - The server's `focus-reading` event (advanceFromReading) still works.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileBilling', () => ({
        readings() {
            return [...this.$root.querySelectorAll('[data-reading]')].filter((el) => ! el.disabled && el.offsetParent !== null);
        },

        focusInput(el) {
            el.focus();
            if (typeof el.select === 'function') {
                el.select();
            }
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },

        focusRef(ref) {
            const el = document.getElementById('rw-m-' + ref);
            if (el) {
                this.focusInput(el);
            }
        },

        nextReading(el) {
            const list = this.readings();
            const next = list[list.indexOf(el) + 1];

            if (next) {
                this.focusInput(next);
            } else {
                el.blur();
            }
        },

        go(method) {
            const proxy = this.$refs.proxy;
            const holdKeyboard = document.activeElement?.matches?.('[data-reading]') ?? false;

            if (holdKeyboard) {
                proxy.focus({ preventScroll: true });
            }

            const settle = () => {
                if (! holdKeyboard) {
                    return;
                }

                const empty = this.readings().find((el) => el.value.trim() === '');
                if (empty) {
                    this.focusInput(empty);
                } else {
                    proxy.blur();
                }
            };

            this.$wire[method]()
                .then(() => this.$nextTick(() => requestAnimationFrame(settle)))
                .catch(() => proxy.blur());
        },

        create() {
            this.$wire
                .createInvoices()
                .finally(() => this.$dispatch('rw-m-sheet-close', { name: 'billing-confirm' }));
        },
    }));
});
