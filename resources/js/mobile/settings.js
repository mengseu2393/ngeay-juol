// /m settings screens — Alpine.data() registrations live here (see resources/js/mobile.js).

// ── Utility Rates (livewire.mobile.utility-rates) ────────────────────────────
//
// Add / edit open instantly from the card's own data (like Simple Mode's popup);
// only Save, Activate/Deactivate, Delete and the reading history hit the server.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileUtilityRates', (blank, defaults) => ({
        form: { ...blank },

        open(data) {
            this.form = { ...data };

            // A previous attempt's validation errors belong to that attempt.
            if (this.$root.querySelector('[data-rw-m-error]')) {
                this.$wire.clearFormErrors();
            }

            this.$dispatch('rw-m-sheet-open', { name: 'utility-form' });
        },

        openAdd() {
            this.open(blank);
        },

        openEdit(card) {
            this.open(card);
        },

        /** A preset name brings its usual unit and billing type with it. */
        applyDefaults() {
            const d = defaults[this.form.name];

            if (d) {
                this.form.unit_of_measure = d.unit;
                this.form.billing_type = d.billing;
            }
        },

        save() {
            const { label, metered, ...data } = this.form;

            this.$wire.save(this.form.id, { ...data, rate: String(data.rate ?? '') });
        },

        openDelete() {
            this.$dispatch('rw-m-sheet-open', { name: 'utility-delete' });
        },

        openHistory() {
            // Only meter-read utilities (as saved, not as edited in the form) have readings.
            if (! this.form.metered) {
                return;
            }

            this.$dispatch('rw-m-sheet-open', { name: 'utility-history' });
            this.$wire.openHistory(this.form.id);
        },
    }));
});

// ── Meter readings (livewire.mobile.readings) ────────────────────────────────
//
// The room list is property-bounded and fully rendered, so the Due / Done / All
// pills and the search filter it in place with no request. Tapping a room opens
// the sheet at once (its title from the row) while the server fills the inputs.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileReadings', (initialFilter, initialRoom) => ({
        filter: initialFilter,
        q: '',
        room: initialRoom,

        test(data) {
            const q = this.q.trim().toLowerCase();

            return (this.filter === 'all' || data.status === this.filter)
                && (q === '' || (data.q || '').includes(q));
        },

        matches(el) {
            return this.test(el.dataset);
        },

        /** True when rooms exist but the current pill/search hides every one. */
        noMatches() {
            const items = this.$root.querySelectorAll('[data-rw-m-item]');

            return items.length > 0 && ! Array.from(items).some((el) => this.test(el.dataset));
        },

        openRoom(room) {
            this.room = room;
            this.$dispatch('rw-m-sheet-open', { name: 'reading' });
            this.$wire.openUtilityReading(room.id);
        },

        /** Enter on a "next" key moves to the next meter instead of submitting. */
        focusNext(input) {
            const inputs = Array.from(input.form.querySelectorAll('[data-rw-m-reading]'));
            inputs[inputs.indexOf(input) + 1]?.focus();
        },
    }));
});
