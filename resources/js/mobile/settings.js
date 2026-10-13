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

// ── Profile (livewire.mobile.profile) ────────────────────────────────────────

/**
 * Downscale a picked photo in the browser before it is uploaded — a copy of
 * Simple Mode's helper (components/rw-simple-popup-script), which /m doesn't
 * load. A phone photo is 3–12MB, which a default server rejects outright (nginx
 * client_max_body_size 1M, PHP upload_max_filesize 2M) before Laravel sees it;
 * an avatar needs ~800px, so this sends a few hundred KB instead. Resolves the
 * original file when the browser can't decode/encode it (or the result isn't
 * smaller), so it can only ever help.
 */
if (! window.rwResizeImage) {
    window.rwResizeImage = async function (file, maxSide, quality) {
        maxSide = maxSide || 800;
        quality = quality || 0.85;

        if (! file || ! file.type || ! file.type.startsWith('image/') || file.type === 'image/gif') {
            return file;
        }

        try {
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

            if (! blob || blob.size >= file.size) {
                return file;
            }

            return new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
        } catch (e) {
            return file;
        }
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileProfile', () => ({
        uploading: false,

        /** Picking a photo uploads it; the server saves it as the avatar on arrival. */
        async pickAvatar(event) {
            const input = event.target;
            const file = input.files && input.files[0];

            if (! file) {
                return;
            }

            this.uploading = true;
            const done = () => (this.uploading = false);
            const upload = await window.rwResizeImage(file, 800);

            this.$wire.upload('avatarPhoto', upload, done, done);
            input.value = '';
        },
    }));
});
