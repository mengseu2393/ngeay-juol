{{--
    Simple Mode popup shell — instant, client-side open/close.

    Usage:
        <x-rw-simple-popup name="tenant-view" close="closeTenantView">
            @if($viewingRental) ...body... @endif
        </x-rw-simple-popup>

    Open it from any element inside the same Livewire component:
        @click="$dispatch('rw-popup-open', { name: 'tenant-view', call: () => $wire.viewTenant({{ $id }}) })"

    Starts open when the server rendered a body (e.g. ?unit_id= deep links).
    The overlay is always in the DOM (hidden), so a tap shows it — and its
    entrance animation — synchronously; `call` runs in the background and the
    placeholder is replaced by the slot once Livewire has morphed the body in.
    Close (×), Cancel, Escape and tap-outside all close instantly on the client
    and then fire `close` on the server to reset its state. Inside the slot,
    `close()` is available for extra buttons (e.g. a form's own Cancel).

    Props:
        name   — event name the popup answers to (also its id prefix)
        close  — Livewire method that clears the server-side "open" state
        cancel — render the full-width Cancel button under the card (default true)
        width  — max-width utility for the card (default max-w-md)
--}}
@props([
    'name',
    'close',
    'cancel' => true,
    'width' => 'max-w-md',
])

<div
    {{-- Keep this attribute static: Livewire morphs re-sync attributes, and a
         value that changes between renders would make Alpine re-initialise
         the popup's state mid-open. Initial open state is read from the DOM. --}}
    x-data="rwSimplePopup({ name: @js($name), closeMethod: @js($close) })"
    x-show="open"
    x-cloak
    @rw-popup-open.window="$event.detail.name === name && show($event.detail.call)"
    @keydown.escape.window="close()"
    {{ $attributes->class(['rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8']) }}
    id="{{ $name }}-popup"
>
    <div class="relative w-full {{ $width }} mt-6" @click.outside="close()">
        <button
            type="button"
            @click="close()"
            class="rw-sm-modal-close-btn"
            id="{{ $name }}-close-btn"
            aria-label="{{ __('Close') }}"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
            </svg>
        </button>

        {{-- Placeholder while the body is on its way (cheap to animate on a phone) --}}
        <div x-show="pending" class="rw-sm-invoice-skeleton rw-sm-popup-skeleton" aria-busy="true">
            <x-filament::loading-indicator class="rw-sm-invoice-skeleton-spinner" />
            <span>{{ __('Loading…') }}</span>
        </div>

        <div x-show="! pending" x-ref="body" class="rw-sm-popup-body">
            {{ $slot }}
        </div>

        @if($cancel)
            <button
                type="button"
                x-show="! pending"
                @click="close()"
                class="rw-sm-btn-secondary w-full mt-3"
                id="{{ $name }}-cancel-btn"
            >{{ __('Cancel') }}</button>
        @endif
    </div>
</div>
