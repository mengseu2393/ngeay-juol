<x-filament-panels::page>
    @php
        $propertyId  = $this->getPropertyId();
        $propertyName = $this->getPropertyName();
    @endphp

    {{-- ─────────────────────────────────────────────────────────── --}}
    {{-- Simple Mode Shell                                          --}}
    {{-- ─────────────────────────────────────────────────────────── --}}
    <div
        class="rw-simple rw-simple--billing mx-auto space-y-5 px-4 py-4 transition-all duration-350"
        x-data="{
            screen: new URLSearchParams(window.location.search).get('screen') || 'home',
            setScreen(s) {
                this.screen = s;
                history.replaceState(null, '', window.location.pathname + '?screen=' + s);
            }
        }"
    >
        {{-- ── Header bar ── --}}
        <div class="rw-sm-header flex items-center justify-between gap-3">
            {{-- Property name --}}
            <div class="min-w-0 flex-1">
                @if($propertyName)
                    <p class="rw-sm-property-label truncate text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400">
                        {{ $propertyName }}
                    </p>
                @else
                    <p class="text-xs text-gray-400">{{ __('No property selected') }}</p>
                @endif
                <h1 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">{{ __('Daily work') }}</h1>
            </div>

        </div>

        {{-- ── No property blocked state ── --}}
        @if(! $propertyId)
            <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center">
                <div class="mb-3 text-4xl">🏠</div>
                <p class="font-semibold text-gray-700 dark:text-gray-300">{{ __('Choose a property first') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Use the sidebar property switcher to select a property.') }}</p>
            </div>

        @else
            {{-- ── Home: action grid ── --}}
            <div x-show="screen === 'home'" x-cloak>
                <div class="rw-sm-grid grid grid-cols-2 gap-3">
                    {{-- Invoices (browse, filter, record payments, and create) --}}
                    <button
                        @click="setScreen('invoices')"
                        class="rw-sm-action-card text-left"
                        id="simple-action-invoices"
                    >
                        <div class="rw-sm-action-icon bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776"/></svg>
                        </div>
                        <span class="rw-sm-action-label">{{ __('Invoices') }}</span>
                        <span class="rw-sm-action-sub">{{ __('Create, check, filter & record payment') }}</span>
                    </button>

                    {{-- Rooms --}}
                    <button
                        @click="setScreen('rooms')"
                        class="rw-sm-action-card text-left"
                        id="simple-action-rooms"
                    >
                        <div class="rw-sm-action-icon bg-sky-100 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"/></svg>
                        </div>
                        <span class="rw-sm-action-label">{{ __('Rooms') }}</span>
                        <span class="rw-sm-action-sub">{{ __('Status, add & end tenancy') }}</span>
                    </button>
                </div>
            </div>

            {{-- ── Invoices screen ── --}}
            <div x-show="screen === 'invoices'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('home')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title flex-1">{{ __('Invoices') }}</h2>
                    <button @click="setScreen('billing-invoice')" class="rw-sm-create-invoice-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        <span>{{ __('Create invoice') }}</span>
                    </button>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-invoice-list', key('simple-invoice-list'))
                </div>
            </div>

            {{-- ── Billing invoice screen ── --}}
            <div x-show="screen === 'billing-invoice'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('home')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Create invoices') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire(\App\Livewire\SimpleBillingInvoice::class, key('simple-billing-invoice'))
                </div>
            </div>

            {{-- ── Add tenant screen ── --}}
            <div x-show="screen === 'add-tenant'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('home')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Add tenant') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-add-tenant', key('simple-add-tenant'))
                </div>
            </div>

            {{-- ── End tenancy screen ── --}}
            <div x-show="screen === 'end-tenancy'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('home')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('End tenancy') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-end-tenancy', key('simple-end-tenancy'))
                </div>
            </div>

            {{-- ── Rooms screen ── --}}
            <div x-show="screen === 'rooms'" x-cloak>
                <div class="rw-sm-screen-header">
                    <button @click="setScreen('home')" class="rw-sm-back-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <h2 class="rw-sm-screen-title">{{ __('Rooms') }}</h2>
                </div>
                <div class="rw-sm-panel overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @livewire('simple-room-list', key('simple-room-list'))
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
