{{--
    Simple Mode "View details" popup — fully client-side, like Record payment.

    The slip HTML for every invoice card on the page is prefetched in the
    background (route invoices.slip, a bare fragment) right after the list
    renders, and again after each Livewire morph (filter / page / search) and
    after a payment is saved. A tap then injects the cached fragment
    synchronously, so the popup opens with no spinner at all. Only an invoice
    that hasn't finished prefetching yet (tap within ~a second of load on a
    slow link) shows the placeholder while its single fetch completes.

    Lives inside SimpleInvoiceList's root; the body is wire:ignore'd so
    Livewire never morphs away the client-injected slip.
--}}
<div
    x-data="{
        open: false,
        pending: false,
        currentId: null,
        print: {},
        cache: {},
        inflight: {},
        fetchSlip(id, url) {
            if (this.cache[id]) return Promise.resolve(this.cache[id]);
            if (this.inflight[id]) return this.inflight[id];
            this.inflight[id] = fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(r => { if (! r.ok) throw new Error(r.status); return r.text(); })
                .then(html => { this.cache[id] = html; return html; })
                .finally(() => { delete this.inflight[id]; });
            return this.inflight[id];
        },
        show(id, url, print) {
            this.open = true;
            this.currentId = id;
            this.print = print || {};
            const cached = this.cache[id];
            if (cached) {
                this.pending = false;
                this.$refs.body.innerHTML = cached;
                return;
            }
            this.pending = true;
            this.$refs.body.innerHTML = '';
            this.fetchSlip(id, url)
                .then(html => {
                    if (this.currentId !== id) return;
                    this.$refs.body.innerHTML = html;
                    this.pending = false;
                })
                .catch(() => { if (this.currentId === id) { this.open = false; this.pending = false; } });
        },
        close() {
            this.open = false;
            this.pending = false;
        },
        /** Warm the cache for every card currently on the page, two at a time. */
        prefetch() {
            const targets = Array.from(this.$root.parentElement.querySelectorAll('[data-slip-id][data-slip-url]'))
                .map(el => ({ id: Number(el.dataset.slipId), url: el.dataset.slipUrl }))
                .filter(t => ! this.cache[t.id] && ! this.inflight[t.id]);
            const run = () => {
                const t = targets.shift();
                if (! t) return;
                this.fetchSlip(t.id, t.url).catch(() => {}).finally(run);
            };
            run(); run();
        },
        invalidate() {
            this.cache = {};
            this.prefetch();
        },
        init() {
            const kick = () => ('requestIdleCallback' in window ? requestIdleCallback(() => this.prefetch()) : setTimeout(() => this.prefetch(), 50));
            kick();
            if (window.Livewire) {
                Livewire.hook('morphed', ({ el }) => { if (el.contains(this.$root)) kick(); });
            }
        },
    }"
    x-show="open"
    x-cloak
    @rw-open-invoice.window="show($event.detail.id, $event.detail.url, $event.detail.print)"
    @pay-saved.window="invalidate()"
    @keydown.escape.window="close()"
    class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
    id="invoice-view-modal"
>
    <div class="relative w-full max-w-4xl mt-6" @click.outside="close()">
        <div x-show="pending" class="rw-sm-invoice-skeleton" aria-busy="true">
            <x-filament::loading-indicator class="rw-sm-invoice-skeleton-spinner" />
            <span>{{ __('Loading…') }}</span>
        </div>

        {{-- rw-sm-hide-invoice-toolbar: Simple Mode only shows the slip itself —
             Print/PDF/View-details live on desktop and the tenant portal via the
             same shared component, so the toolbar is hidden here with CSS. --}}
        <div x-show="! pending" x-ref="body" wire:ignore class="rw-sm-hide-invoice-toolbar rw-sm-invoice-view-body"></div>

        {{-- Actions sit inside the slip card (its footer): Cancel, Print 58mm,
             Share to Telegram. With the slip in the DOM, rwPrintInvoice58mm
             prints it client-side; the data-* URLs are the server-PDF fallback
             and what the Telegram share sends (A4 PDF). --}}
        <div x-show="! pending" class="rw-sm-invoice-actions">
            <button type="button" @click="close()" class="rw-sm-btn-secondary flex-1" id="invoice-view-cancel-btn">
                {{ __('Cancel') }}
            </button>
            <button
                type="button"
                onclick="rwPrintInvoice58mm(this)"
                :data-stream-url="print.streamUrl"
                :data-download-url="print.downloadUrl"
                :data-filename="print.filename"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-sm-btn-primary flex-1 flex items-center justify-center gap-1"
                id="invoice-view-print-58mm-btn"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v1.5A1.75 1.75 0 0 1 16.75 6H18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-.25v1.25a1.75 1.75 0 0 1-1.75 1.75h-8.5A1.75 1.75 0 0 1 4 17.25V16H3.75A2 2 0 0 1 1.75 14V8a2 2 0 0 1 2-2h1.25A1.75 1.75 0 0 1 6.75 4.25v-1.5ZM6.5 4.25c0-.138.112-.25.25-.25h6.5c.138 0 .25.112.25.25v1.5c0 .138-.112.25-.25.25h-6.5a.25.25 0 0 1-.25-.25v-1.5ZM5.5 17.25c0-.138.112-.25.25-.25h8.5c.138 0 .25.112.25.25v-3.5c0-.138-.112-.25-.25-.25h-8.5c-.138 0-.25.112-.25.25v3.5Z" clip-rule="evenodd" />
                </svg>
                <span data-label>{{ __('Print 58mm') }}</span>
            </button>
            <button
                type="button"
                onclick="rwShareInvoiceTelegram(this)"
                :data-download-url="print.shareDownloadUrl"
                :data-filename="print.filename"
                :data-share-text="print.shareText"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-sm-btn-telegram flex-1 flex items-center justify-center gap-1"
                id="invoice-view-share-telegram-btn"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                    <path d="M9.04 15.47 8.7 20.2c.48 0 .69-.21.94-.46l2.26-2.16 4.68 3.43c.86.47 1.47.22 1.7-.79l3.07-14.4c.28-1.26-.45-1.75-1.29-1.44L2.05 11.3c-1.23.48-1.21 1.17-.21 1.48l4.62 1.44 10.73-6.77c.5-.33.96-.15.58.18L9.04 15.47Z" />
                </svg>
                <span data-label>{{ __('Share to Telegram') }}</span>
            </button>
        </div>
    </div>
</div>
