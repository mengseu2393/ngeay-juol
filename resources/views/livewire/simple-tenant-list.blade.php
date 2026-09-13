<div class="space-y-4">

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Room or tenant name…') }}"
            class="rw-sm-search-input"
            id="tenant-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    {{-- ── Tenant detail / login popup (duplicated from simple-room-list.blade.php
         — see App\Livewire\SimpleTenantList's class docblock) ── --}}
    @if($viewingRental)
        <div
            x-data="{
                copied: null,
                copy(text, key) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copied = key;
                        setTimeout(() => { if (this.copied === key) this.copied = null; }, 1500);
                    });
                },
            }"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
            @keydown.escape.window="$wire.closeTenantView()"
        >
            <div class="relative w-full max-w-md mt-6" @click.outside="$wire.closeTenantView()">
                <button
                    type="button"
                    wire:click="closeTenantView"
                    class="rw-sm-modal-close-btn"
                    id="tenant-view-close-btn"
                    aria-label="{{ __('Close') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>

                <div class="rw-sm-modal w-full">
                    <h3 class="rw-sm-modal-title">{{ $viewingRental->occupant_name ?: __('Tenant') }}</h3>
                    <p class="rw-sm-modal-sub">{{ __('Room') }} {{ $viewingRental->unit?->room_number }}</p>

                    {{-- ── Occupant details ── --}}
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        @if($viewingRental->occupant_phone)
                            <div>
                                <p class="rw-sm-detail-label">{{ __('Phone') }}</p>
                                <p class="rw-sm-detail-value">{{ $viewingRental->occupant_phone }}</p>
                            </div>
                        @endif
                        @if($viewingRental->occupant_id_card)
                            <div>
                                <p class="rw-sm-detail-label">{{ __('ID card') }}</p>
                                <p class="rw-sm-detail-value">{{ $viewingRental->occupant_id_card }}</p>
                            </div>
                        @endif
                        @if($viewingRental->monthly_rent)
                            <div>
                                <p class="rw-sm-detail-label">{{ __('Monthly rent') }}</p>
                                <p class="rw-sm-detail-value">{{ \App\Support\Money::format($viewingRental->monthly_rent, $viewingRental->unit?->property?->currency) }}</p>
                            </div>
                        @endif
                        @if($viewingRental->start_date)
                            <div>
                                <p class="rw-sm-detail-label">{{ __('Move-in') }}</p>
                                <p class="rw-sm-detail-value">{{ $viewingRental->start_date->format('d M Y') }}</p>
                            </div>
                        @endif
                        @if($viewingRental->occupant_address)
                            <div class="col-span-2">
                                <p class="rw-sm-detail-label">{{ __('Address') }}</p>
                                <p class="rw-sm-detail-value">{{ $viewingRental->occupant_address }}</p>
                            </div>
                        @endif
                        @if($viewingRental->emergency_contact_name || $viewingRental->emergency_contact_phone)
                            <div class="col-span-2">
                                <p class="rw-sm-detail-label">{{ __('Emergency contact') }}</p>
                                <p class="rw-sm-detail-value">
                                    {{ $viewingRental->emergency_contact_name }}
                                    @if($viewingRental->emergency_contact_phone) — {{ $viewingRental->emergency_contact_phone }} @endif
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- ── Portal login ── --}}
                    <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="rw-sm-label">{{ __('Tenant portal login') }}</p>

                        @if($viewingRental->tenant?->username)
                            <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-2">
                                <span class="text-sm font-mono text-gray-900 dark:text-white truncate">{{ $viewingRental->tenant->username }}</span>
                                <button
                                    type="button"
                                    @click="copy(@js($viewingRental->tenant->username), 'username')"
                                    class="rw-sm-btn-secondary text-xs shrink-0"
                                    id="tenant-view-copy-username"
                                >
                                    <span x-show="copied !== 'username'">{{ __('Copy') }}</span>
                                    <span x-show="copied === 'username'" x-cloak>{{ __('Copied!') }}</span>
                                </button>
                            </div>
                        @endif

                        @if($newPassword)
                            {{-- Shown exactly once, right after (re)generating — same rule
                                 as RentalResource\Actions\TenantLogin's desktop notification:
                                 it cannot be retrieved again once this popup closes. --}}
                            <div class="mt-3 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-3">
                                <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    {{ __('New password — copy it now, it will not be shown again.') }}
                                </p>
                                <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-white dark:bg-gray-900 px-3 py-2">
                                    <span class="text-sm font-mono text-gray-900 dark:text-white truncate">{{ $newPassword }}</span>
                                    <button
                                        type="button"
                                        @click="copy(@js($newPassword), 'password')"
                                        class="rw-sm-btn-secondary text-xs shrink-0"
                                        id="tenant-view-copy-password"
                                    >
                                        <span x-show="copied !== 'password'">{{ __('Copy') }}</span>
                                        <span x-show="copied === 'password'" x-cloak>{{ __('Copied!') }}</span>
                                    </button>
                                </div>
                            </div>
                        @else
                            <button
                                type="button"
                                @click="if (! @js((bool) $viewingRental->tenant?->username) || confirm(@js(__('This replaces the current password — the tenant will need the new one to sign in. Continue?')))) { $wire.resetTenantLogin({{ $viewingRental->id }}) }"
                                wire:loading.attr="disabled"
                                wire:target="resetTenantLogin({{ $viewingRental->id }})"
                                class="rw-sm-btn-ghost text-sm mt-2 w-full"
                                id="tenant-view-reset-login"
                            >
                                <span wire:loading.remove wire:target="resetTenantLogin({{ $viewingRental->id }})">
                                    {{ $viewingRental->tenant?->username ? __('Reset password') : __('Create login') }}
                                </span>
                                <span wire:loading wire:target="resetTenantLogin({{ $viewingRental->id }})">{{ __('Loading…') }}</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Tenant list ── --}}
    @forelse($tenants as $rental)
        <div class="rw-sm-invoice-card" id="tenant-card-{{ $rental->id }}">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="rw-sm-room-number">{{ $rental->unit?->room_number }}</p>
                    <p class="rw-sm-tenant-name">{{ $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant')) }}</p>
                    @if($rental->occupant_phone)
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $rental->occupant_phone }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-3">
                <button
                    type="button"
                    wire:click="viewTenant({{ $rental->id }})"
                    class="rw-sm-btn-ghost text-sm"
                    id="tenant-view-btn-{{ $rental->id }}"
                >{{ __('View tenant') }}</button>
            </div>
        </div>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No tenants found.') }}</p>
        </div>
    @endforelse
</div>
