{{--
    Persistent bottom tab-bar navigation for mobile Simple Mode.

    This partial is @include()'d inside simple-dashboard.blade.php's root
    `x-data` element and deliberately has no `x-data` of its own — `screen`
    and `setScreen()` are inherited from that parent Alpine scope.

    Sub-screens roll up into their owning tab for the active-state highlight:
      - "billing-invoice"            -> Invoice tab
      - "add-tenant", "end-tenancy"  -> Room tab
--}}
<nav class="rw-simple-bottom-nav" role="navigation" aria-label="{{ __('Simple mode navigation') }}">
    <button
        type="button"
        @click="setScreen('invoices')"
        :class="['invoices', 'billing-invoice'].includes(screen) ? 'is-active' : ''"
        class="rw-simple-bottom-nav-item"
    >
        <x-heroicon-o-document-currency-dollar class="h-6 w-6" />
        <span>{{ __('Invoice') }}</span>
    </button>

    <button
        type="button"
        @click="setScreen('rooms')"
        :class="['rooms', 'add-tenant', 'end-tenancy'].includes(screen) ? 'is-active' : ''"
        class="rw-simple-bottom-nav-item"
    >
        <x-heroicon-o-home class="h-6 w-6" />
        <span>{{ __('Room') }}</span>
    </button>

    <button
        type="button"
        @click="setScreen('utility')"
        :class="screen === 'utility' ? 'is-active' : ''"
        class="rw-simple-bottom-nav-item"
    >
        <x-heroicon-o-bolt class="h-6 w-6" />
        <span>{{ __('Utility') }}</span>
    </button>

    <button
        type="button"
        @click="setScreen('settings')"
        :class="screen === 'settings' ? 'is-active' : ''"
        class="rw-simple-bottom-nav-item"
    >
        <x-heroicon-o-cog-6-tooth class="h-6 w-6" />
        <span>{{ __('Settings') }}</span>
    </button>
</nav>
