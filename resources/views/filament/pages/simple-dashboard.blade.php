<x-filament-panels::page>
    @php
        $propertyId = $this->getPropertyId();

        // Only the screen being opened is rendered with the page; every other screen is a
        // lazy Livewire component that loads after first paint. Mounting all nine up front
        // (250KB of HTML, ~60 queries) is what made Simple Mode slow to open.
        //  - main tabs (invoices/rooms/tenants): 'on-load' — fetched right after paint, so
        //    the bottom-nav tap is instant;
        //  - everything else: loaded the first time its (x-show hidden) container is shown.
        // ?unit_id= deep links (add-tenant / end-tenancy / utility) always arrive as the
        // initial screen, so their mount() still sees the query string.
        $initialScreen = request()->query('screen') ?: 'invoices';
        $lazyScreen = fn (string $screen): array => $screen === $initialScreen
            ? []
            : ['lazy' => in_array($screen, ['invoices', 'rooms', 'tenants'], true) ? 'on-load' : true];
    @endphp

    {{-- ─────────────────────────────────────────────────────────── --}}
    {{-- Simple Mode Shell                                          --}}
    {{-- ─────────────────────────────────────────────────────────── --}}
    <div
        class="rw-simple rw-simple--billing mx-auto space-y-5 px-4 py-4 transition-all duration-350 {{ $propertyId ? 'rw-simple--with-bottom-nav' : '' }}"
        x-data="{
            screen: @js($initialScreen),
            setScreen(s) {
                this.screen = s;
                history.replaceState(null, '', window.location.pathname + '?screen=' + s);
            }
        }"
    >
        {{-- Header bar removed to save vertical space on mobile — the bottom
             tab bar is primary navigation and the property switcher lives in
             the topbar (see LandlordPanelProvider's TOPBAR_START hook), so
             there's nothing this heading needs to convey. ── --}}

        {{-- ── No property blocked state ── --}}
        @if(! $propertyId)
            <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center">
                <div class="mb-3 text-4xl">🏠</div>
                <p class="font-semibold text-gray-700 dark:text-gray-300">{{ __('Choose a property first') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Use the property switcher above to select a property.') }}</p>
            </div>

        @else
            {{-- ── Invoices screen ── --}}
            <div x-show="screen === 'invoices'" x-cloak>
                <div class="rw-sm-screen-header">
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Invoices') }}</h2>
                    <button @click="setScreen('billing-invoice')" class="rw-sm-create-invoice-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>{{ __('Create invoice') }}</span>
                    </button>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-invoice-list', $lazyScreen('invoices'), key('simple-invoice-list'))
                </div>
            </div>

            {{-- ── Billing invoice screen ── --}}
            <div x-show="screen === 'billing-invoice'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('invoices')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Create invoices') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire(\App\Livewire\SimpleBillingInvoice::class, $lazyScreen('billing-invoice'), key('simple-billing-invoice'))
                </div>
            </div>

            {{-- ── Add tenant screen ── --}}
            <div x-show="screen === 'add-tenant'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('rooms')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Add tenant') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-add-tenant', $lazyScreen('add-tenant'), key('simple-add-tenant'))
                </div>
            </div>

            {{-- ── End tenancy screen ── --}}
            <div x-show="screen === 'end-tenancy'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('rooms')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('End tenancy') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-end-tenancy', $lazyScreen('end-tenancy'), key('simple-end-tenancy'))
                </div>
            </div>

            {{-- ── Rooms screen ── --}}
            <div x-show="screen === 'rooms'" x-cloak>
                <div class="rw-sm-screen-header">
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Rooms') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-room-list', $lazyScreen('rooms'), key('simple-room-list'))
                </div>
            </div>

            {{-- ── Units screen: every room of the active property (reached from Settings → Units);
                 each card opens the room screen. ── --}}
            <div x-show="screen === 'units'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('settings')" class="rw-sm-back-btn" aria-label="{{ __('Settings') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Units') }}</h2>
                </div>
                @livewire(\App\Livewire\PropertyUnitsManager::class, ['propertyId' => $propertyId] + $lazyScreen('units'), key('simple-units'))
            </div>

            {{-- ── Room screen: one room start to finish (agreement, readings, history, reset).
                 Deep-linked from a room card with ?screen=unit&unit_id=N. ── --}}
            <div x-show="screen === 'unit'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('rooms')" class="rw-sm-back-btn" aria-label="{{ __('Rooms') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Room') }}</h2>
                </div>
                @livewire(\App\Livewire\SimpleUnitDetail::class, $lazyScreen('unit'), key('simple-unit-detail'))
            </div>

            {{-- ── Tenants screen ── --}}
            <div x-show="screen === 'tenants'" x-cloak>
                <div class="rw-sm-screen-header">
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Tenants') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-tenant-list', $lazyScreen('tenants'), key('simple-tenant-list'))
                </div>
            </div>

            {{-- ── Utility screen — no longer a bottom-nav tab (that slot is now
                 Tenants); reached via the "Utility" link on the Settings screen
                 instead, so this stays in the Alpine screen set but doesn't
                 roll up into any bottom-nav tab's active state. ── --}}
            <div x-show="screen === 'utility'" x-cloak>
                <div class="rw-sm-screen-header">
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Utility') }}</h2>
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'utility-rates']) }}" @click.prevent="setScreen('utility-rates')" class="rw-sm-create-invoice-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>{{ __('Add utility') }}</span>
                    </a>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 -mt-3 mb-1">
                    {{ __('Setting up rates & prices?') }}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'utility-rates']) }}" @click.prevent="setScreen('utility-rates')" class="font-semibold text-primary-600 dark:text-primary-400 underline">{{ __('Manage utility rates') }}</a>
                </p>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-utility-usage', $lazyScreen('utility'), key('simple-utility-usage'))
                </div>
            </div>

            {{-- ── Utility rates screen (replaces the ?from=simple detour through
                 PropertyUtilityResource's list page) ── --}}
            <div x-show="screen === 'utility-rates'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('settings')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Utility Rates') }}</h2>
                    <button type="button" @click="$dispatch('rw-utility-rate-add')" class="rw-sm-create-invoice-btn" id="utility-rates-add-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>{{ __('Add utility') }}</span>
                    </button>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire(\App\Livewire\SimpleUtilityRates::class, $lazyScreen('utility-rates'), key('simple-utility-rates'))
                </div>
            </div>

            {{-- ── Profile screen ── --}}
            <div x-show="screen === 'profile'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('settings')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Profile') }}</h2>
                </div>
                @livewire('simple-profile', $lazyScreen('profile'), key('simple-profile'))
            </div>

            {{-- ── Settings screen ── --}}
            <div x-show="screen === 'settings'" x-cloak>
                <div class="rw-sm-screen-header">
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Settings') }}</h2>
                </div>
                @include('filament.pages.partials.simple-settings')
            </div>

            @include('components.rw-simple-bottom-nav')
        @endif
    </div>
</x-filament-panels::page>
