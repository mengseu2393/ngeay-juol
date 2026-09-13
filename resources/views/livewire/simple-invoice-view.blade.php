<div
    x-data
    class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
    @keydown.escape.window="$wire.closeView()"
>
    <div class="relative w-full max-w-4xl mt-6" @click.outside="$wire.closeView()">
        {{-- rw-sm-hide-invoice-toolbar: Simple Mode only shows the invoice
             slip itself — Print/PDF/View-details live on desktop and the
             tenant portal via this same shared component, so the toolbar
             is hidden here with CSS rather than removed from the component. --}}
        <div class="rw-sm-hide-invoice-toolbar">
            @include('components.invoice-slip-modal', ['invoice' => $invoice])
        </div>

        <button
            type="button"
            wire:click="closeView"
            class="rw-sm-btn-secondary w-full mt-3"
            id="invoice-view-cancel-btn"
        >
            {{ __('Cancel') }}
        </button>
    </div>
</div>
