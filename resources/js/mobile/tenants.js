// /m tenants screens — Alpine.data() registrations live here (see resources/js/mobile.js).

/**
 * Downscale a picked photo in the browser before it is uploaded (copied from
 * components.rw-simple-popup-script, which /m doesn't load; another /m area may
 * define it first — same function, so either copy wins).
 *
 * A phone photo is 3–12MB, which a default server rejects outright (nginx
 * client_max_body_size 1M, PHP upload_max_filesize 2M) before Laravel sees it.
 * Resolves the original file when the browser can't decode/encode it (or the
 * result isn't smaller), so it can only ever help.
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

/** Copy to the clipboard, with a fallback for http:// (no Clipboard API off a secure origin). */
function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    area.remove();

    return Promise.resolve();
}

document.addEventListener('alpine:init', () => {
    // ── Tenants list (livewire.mobile.tenants): instant search over the rendered cards ──
    window.Alpine.data('rwMobileTenants', () => ({
        q: '',

        terms() {
            return this.q.toLowerCase().trim().split(/\s+/).filter(Boolean);
        },

        /** Every typed word must appear (cards carry a digits-only copy too, so "012345" finds "012 345"). */
        matches(el) {
            const haystack = el.dataset.q || '';

            return this.terms().every((term) => haystack.includes(term));
        },

        noMatches() {
            const cards = this.$root.querySelectorAll('[data-rw-m-tenant]');

            return this.terms().length > 0 && cards.length > 0 && ! Array.from(cards).some((el) => this.matches(el));
        },
    }));

    // ── One tenant (livewire.mobile.tenant): invoice slip sheet, ID photo viewer, copy buttons ──
    window.Alpine.data('rwMobileTenant', (failedMessage) => ({
        copied: null,
        invoice: null,
        slipLoading: false,
        slips: {},
        photo: null,

        init() {
            // A payment elsewhere changes the slips; drop what was cached.
            this.$wire.on('pay-saved', () => (this.slips = {}));
        },

        copy(text, key) {
            copyText(text).then(() => {
                this.copied = key;
                setTimeout(() => {
                    if (this.copied === key) {
                        this.copied = null;
                    }
                }, 1500);
            });
        },

        openPhoto(url) {
            this.photo = url;
            this.$dispatch('rw-m-sheet-open', { name: 'tenant-photo' });
        },

        openInvoice(card) {
            this.invoice = card;
            this.$dispatch('rw-m-sheet-open', { name: 'tenant-invoice' });

            const body = document.getElementById('rw-m-tenant-slip');
            body.innerHTML = this.slips[card.id] || '';
            this.slipLoading = ! this.slips[card.id];

            if (! this.slipLoading) {
                return;
            }

            fetch(card.slipUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                })
                .then((html) => {
                    this.slips[card.id] = html;

                    if (this.invoice?.id === card.id) {
                        body.innerHTML = html;
                    }
                })
                .catch(() => {
                    this.$dispatch('rw-m-sheet-close', { name: 'tenant-invoice' });
                    window.rwMobileToast(failedMessage);
                })
                .finally(() => (this.slipLoading = false));
        },
    }));

    // ── Edit tenant sheet (livewire.mobile.tenant-edit): ID photos, shrunk before upload ──
    window.Alpine.data('rwMobileTenantEdit', (failedMessage) => ({
        uploading: false,

        async pickPhotos(event) {
            const input = event.target;
            const picked = Array.from(input.files || []);
            input.value = '';

            if (picked.length === 0) {
                return;
            }

            this.uploading = true;

            // ID cards need to stay legible: a longer side than an avatar's.
            const files = await Promise.all(picked.map(async (file, index) => {
                const resized = await window.rwResizeImage(file, 1600, 0.85);

                return resized === file ? file : new File([resized], `id-card-${Date.now()}-${index}.jpg`, { type: 'image/jpeg' });
            }));

            const done = () => (this.uploading = false);
            const failed = () => {
                this.uploading = false;
                window.rwMobileToast(failedMessage);
            };

            // append = true: a second pick adds to the first instead of replacing it.
            this.$wire.uploadMultiple('idCardPhotos', files, done, failed, () => {}, done, true);
        },
    }));
});
