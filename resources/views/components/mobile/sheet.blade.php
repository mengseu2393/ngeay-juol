{{--
    A bottom sheet for the /m shell. Opened and closed by window events
    (rw-m-sheet-open / rw-m-sheet-close with { name }); the behaviour — its own
    history entry so the back button/gesture closes it, scroll lock, one sheet at
    a time, drag down to dismiss — is Alpine.data('rwMobileSheet') in
    resources/js/mobile.js.

    Nested inside a screen's x-data, so the slot can read that screen's state.
    Not teleported: nothing in the /m layout creates a containing block for
    position: fixed, so it sits above the tab bar as it is.
--}}
@props(['name', 'label' => null])

<div
    x-data="rwMobileSheet(@js($name))"
    x-on:rw-m-sheet-open.window="$event.detail.name === name && show()"
    x-on:rw-m-sheet-close.window="$event.detail.name === name && hide()"
    x-on:keydown.escape.window="open && hide()"
    {{ $attributes }}
>
    <div x-show="open" x-cloak x-transition.opacity.duration.200ms class="rw-m-sheet-backdrop" x-on:click="hide()"></div>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="rw-m-sheet"
        x-ref="panel"
        x-on:touchstart="dragStart($event)"
        x-on:touchmove="dragMove($event)"
        x-on:touchend="dragEnd()"
        x-on:touchcancel="dragEnd()"
        role="dialog"
        aria-modal="true"
        @if ($label) aria-label="{{ $label }}" @endif
    >
        <div class="rw-m-sheet-handle" aria-hidden="true"></div>
        {{ $slot }}
    </div>
</div>
