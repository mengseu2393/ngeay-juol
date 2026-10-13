// /m rooms screens — Alpine.data() registrations live here (see resources/js/mobile.js).
//
// Rooms (livewire.mobile.rooms), Room (livewire.mobile.room) and Add tenant
// (livewire.mobile.add-tenant). Everything a tap needs is already on the page,
// so filter pills, search and opening a sheet never wait on the server; only
// Save does.

/**
 * Downscale a picked photo in the browser before it is uploaded — the same
 * helper Simple Mode has (components.rw-simple-popup-script), which /m does not
 * load. A phone photo is 3–12MB, which a default server rejects outright
 * (nginx client_max_body_size 1M, PHP upload_max_filesize 2M) before Laravel
 * sees it. Resolves the original file when the browser can't decode/encode it
 * (or the result isn't smaller), so it can only ever help.
 */
if (typeof window.rwResizeImage !== 'function') {
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

            const name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';

            return new File([blob], name, { type: 'image/jpeg' });
        } catch (e) {
            return file;
        }
    };
}

document.addEventListener('alpine:init', () => {
    // ── Rooms screen ─────────────────────────────────────────────────────────
    //
    // Every room of the property is rendered, so a pill tap or a keystroke only
    // shows/hides cards (data-rw-item, data-status, data-q — like Simple Mode's
    // rwListFilter). The per-room actions, price and reading sheets open from
    // the card's own data.
    window.Alpine.data('rwMobileRooms', (initialFilter) => ({
        q: '',
        filter: initialFilter || 'all',
        room: null,
        price: { id: null, room: '', currency: '', value: '' },
        reading: { id: null, room: '', values: {} },

        matches(el) {
            return this.test(el.dataset);
        },

        test(data) {
            const q = this.q.trim().toLowerCase();

            return (this.filter === 'all' || data.status === this.filter)
                && (q === '' || (data.q || '').includes(q));
        },

        /** True when rooms exist but the current pill/search hides every one. */
        noMatches() {
            const items = this.$root.querySelectorAll('[data-rw-item]');

            return items.length > 0 && ! Array.from(items).some((el) => this.test(el.dataset));
        },

        setFilter(value) {
            this.filter = value;

            // Keep the pill in the URL (a reload or a shared link opens on it) without a request.
            try {
                const url = new URL(window.location.href);
                value === 'all' ? url.searchParams.delete('filter') : url.searchParams.set('filter', value);
                history.replaceState(history.state, '', url);
            } catch (e) {}
        },

        openActions(card) {
            this.room = card;
            this.$dispatch('rw-m-sheet-open', { name: 'room-actions' });
        },

        openPrice(card) {
            this.price = { id: card.id, room: card.room, currency: card.currency, value: card.price };
            this.$dispatch('rw-m-sheet-open', { name: 'room-price' });
        },

        savePrice() {
            this.$wire.submitEditPriceFor(this.price.id, String(this.price.value ?? ''));
        },

        openReading(card) {
            this.reading = { id: card.id, room: card.room, values: {} };
            this.$dispatch('rw-m-sheet-open', { name: 'room-reading' });
        },

        saveReading() {
            this.$wire.submitUtilityReadingFor(this.reading.id, this.reading.values);
        },
    }));

    // ── Room screen ──────────────────────────────────────────────────────────
    //
    // The reading / reset / correction forms are server state (Concerns\ManagesUnit
    // keeps which one is open), so a sheet opens at once and its form arrives with
    // the response. Dismissing a sheet tells the server the form is closed.
    window.Alpine.data('rwMobileRoom', () => ({
        open(name, call) {
            this.$dispatch('rw-m-sheet-open', { name });
            call();
        },

        closed(event) {
            const name = event.detail && event.detail.name;

            if ((name === 'reading' || name === 'reset') && this.$wire.panel) {
                this.$wire.closePanel();
            } else if (name === 'correct' && this.$wire.editingId) {
                this.$wire.cancelEdit();
            }
        },
    }));

    // ── Add tenant screen: ID card photos ────────────────────────────────────
    //
    // Up to `max` photos, each shrunk in the browser first and uploaded on its
    // own, so the camera (one shot at a time) can take the front, then the back.
    window.Alpine.data('rwMobileIdPhotos', (max, failedMessage) => ({
        uploading: false,

        async pick(event) {
            const input = event.target;
            const files = Array.from(input.files || []);
            input.value = '';

            const room = Math.max(0, max - Number(this.$wire.idCardPhotos?.length || 0));

            if (! files.length || room === 0) {
                return;
            }

            this.uploading = true;

            try {
                const resized = await Promise.all(files.slice(0, room).map((file) => window.rwResizeImage(file, 1600, 0.85)));

                await new Promise((resolve, reject) => {
                    this.$wire.uploadMultiple('newIdCardPhotos', resized, resolve, reject);
                });
            } catch (e) {
                window.rwMobileToast && window.rwMobileToast(failedMessage);
            } finally {
                this.uploading = false;
            }
        },
    }));
});
