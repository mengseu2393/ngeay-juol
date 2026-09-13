{{--
    Simple Mode invoice-view popup.

    The overlay is toggled purely client-side (`open`), so it — and its
    entrance animation (see .rw-sm-modal-overlay in rentwise-admin.css) —
    appear synchronously on tap. `pending` covers the window between the tap
    and the server response: the popup shows a lightweight placeholder card
    (cheap to animate on a phone) and the real slip fades in once the body
    has been morphed in. Closing is instant and never hits the server.

    Root element = the overlay itself: it is `fixed` when shown and
    `display:none` when hidden, so it never disturbs the list's flow layout.
--}}
<div
    x-data="{
        open: false,
        pending: false,
        show(id) {
            this.open = true;
            this.pending = true;
            $wire.open(id)
                .then(() => { this.pending = false; })
                .catch(() => { this.open = false; this.pending = false; });
        },
    }"
    x-show="open"
    x-cloak
    @rw-open-invoice.window="show($event.detail.id)"
    @keydown.escape.window="open = false"
    class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
    id="invoice-view-modal"
>
    <div class="relative w-full max-w-4xl mt-6" @click.outside="open = false">
        {{-- Placeholder while the invoice body is on its way --}}
        <div x-show="pending" class="rw-sm-invoice-skeleton" aria-busy="true">
            <x-filament::loading-indicator class="rw-sm-invoice-skeleton-spinner" />
            <span>{{ __('Loading…') }}</span>
        </div>

        {{-- rw-sm-hide-invoice-toolbar: Simple Mode only shows the invoice
             slip itself — Print/PDF/View-details live on desktop and the
             tenant portal via this same shared component, so the toolbar
             is hidden here with CSS rather than removed from the component. --}}
        <div x-show="! pending" class="rw-sm-hide-invoice-toolbar rw-sm-invoice-view-body">
            @if($invoice)
                @include('components.invoice-slip-modal', ['invoice' => $invoice])
            @endif
        </div>

        <button
            type="button"
            @click="open = false"
            class="rw-sm-btn-secondary w-full mt-3"
            id="invoice-view-cancel-btn"
        >
            {{ __('Cancel') }}
        </button>
    </div>
</div>
