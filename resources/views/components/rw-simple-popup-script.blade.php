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
</script>
