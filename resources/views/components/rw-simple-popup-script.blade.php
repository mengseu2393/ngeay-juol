{{--
    Alpine factory behind <x-rw-simple-popup> (Simple Mode's popups).

    Loaded from <head> (LandlordPanelProvider's HEAD_END hook) so it exists
    before Alpine evaluates any x-data — including after Livewire SPA
    navigations, which keep <head> and only swap <body>.

    The popup shell is client-owned: `open` flips synchronously on tap so the
    overlay + entrance animation paint on the very next frame, `pending` shows
    a lightweight placeholder while the (optional) Livewire call that fills
    the body is in flight, and closing never waits on the server — the
    server-side "close" call only tidies state in the background.
--}}
<script>
    window.rwSimplePopup = function (config) {
        return {
            name: config.name,
            open: false,
            pending: false,

            init() {
                // Server rendered a body on first paint (e.g. a ?unit_id= deep link).
                this.open = this.$refs.body.children.length > 0;

                // When the server empties the body on its own (a successful
                // save, a "switch to the edit popup" action, ...) the shell
                // follows suit instead of hanging around as an empty card.
                new MutationObserver(() => {
                    if (this.open && ! this.pending && this.$refs.body.children.length === 0) {
                        this.open = false;
                    }
                }).observe(this.$refs.body, { childList: true });
            },

            /** @param {(() => Promise<any>)|undefined} call — the $wire call that fills the body. */
            show(call) {
                this.open = true;
                this.pending = typeof call === 'function';

                if (! this.pending) {
                    return;
                }

                Promise.resolve(call())
                    .then(() => {
                        this.pending = false;
                        if (this.$refs.body.children.length === 0) {
                            this.open = false;
                        }
                    })
                    .catch(() => {
                        this.pending = false;
                        this.open = false;
                    });
            },

            close() {
                const wasOpen = this.open;

                // Instant, transition-free hide: a CSS leave transition never
                // completes while the tab is backgrounded, which could strand
                // a half-closed overlay over the page.
                this.open = false;
                this.pending = false;

                if (wasOpen && config.closeMethod && this.$wire) {
                    this.$wire[config.closeMethod]();
                }
            },
        };
    };

    /**
     * Instant, request-free filter/search over cards the server already rendered.
     *
     * Rooms, tenants and utility rows are property-bounded and fully rendered, so a filter
     * pill tap or a search keystroke needs no server round-trip (each used to cost one).
     * Cards opt in with `data-rw-item`, `data-status` (matched against the active pill) and
     * `data-q` (pre-lowercased searchable text), and hide via `x-show="matches($el)"`.
     * The paginated invoice list keeps its server-side search — it isn't fully loaded.
     */
    window.rwListFilter = function (config) {
        config = config || {};

        return {
            q: '',
            filter: config.filter || 'all',

            matches(el) {
                return this.test(el.dataset);
            },

            test(data) {
                const q = this.q.trim().toLowerCase();

                return (this.filter === 'all' || data.status === this.filter)
                    && (q === '' || (data.q || '').includes(q));
            },

            /** True when cards exist but the current filter/search hides every one. */
            noMatches() {
                const items = this.$root.querySelectorAll('[data-rw-item]');

                return items.length > 0 && ! Array.from(items).some((el) => this.test(el.dataset));
            },
        };
    };

    /**
     * Downscale a picked photo in the browser before it is uploaded.
     *
     * A phone photo is 3–12MB, which a default server rejects outright (nginx
     * client_max_body_size 1M, PHP upload_max_filesize 2M) before Laravel sees it — the upload
     * "works on local" and fails in production. An avatar needs ~800px, so this sends a few
     * hundred KB instead. Resolves the original file when the browser can't decode/encode it
     * (or the result isn't smaller), so it can only ever help.
     */
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
</script>
