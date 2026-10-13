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
