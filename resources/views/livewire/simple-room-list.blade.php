<div
    class="space-y-4"
    x-data="{
        priceOpen: false,
        price: { id: null, room: '', currency: '', value: '' },
        openPrice(unit) { this.price = { ...unit }; this.priceOpen = true; },

        readingOpen: false,
        reading: { id: null, room: '', values: {} },
        openReading(unit) { this.reading = { ...unit, values: {} }; this.readingOpen = true; },

        tenantOpen: false,
        tenant: {},
        newPassword: null,
        resetting: false,
        copied: null,
        openTenant(data) { this.tenant = { ...data }; this.newPassword = null; this.copied = null; this.tenantOpen = true; },
        resetLogin() {
            if (this.tenant.username && ! confirm(@js(__('This replaces the current password — the tenant will need the new one to sign in. Continue?')))) return;
            this.resetting = true;
            $wire.resetTenantLogin(this.tenant.id)
                .then(r => { if (r && r.password) { this.tenant.username = r.username; this.newPassword = r.password; } })
                .finally(() => { this.resetting = false; });
        },
        copy(text, key) {
            navigator.clipboard.writeText(text).then(() => {
                this.copied = key;
                setTimeout(() => { if (this.copied === key) this.copied = null; }, 1500);
            });
        },
    }"
    @room-price-saved.window="priceOpen = false"
    @room-reading-saved.window="readingOpen = false"
>
    <style>[x-cloak] { display: none !important; }</style>

    {{-- ── Filters ── --}}
    <div class="rw-sm-filter-bar flex gap-2 overflow-x-auto pb-1">
        @foreach(['all' => __('All'), 'available' => __('Vacant'), 'occupied' => __('Occupied'), 'maintenance' => __('Maintenance')] as $val => $label)
            <button
                wire:click="$set('filter', '{{ $val }}')"
                id="room-filter-{{ $val }}"
                class="rw-sm-filter-pill {{ $filter === $val ? 'rw-sm-filter-active' : '' }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Room or tenant name…') }}"
            class="rw-sm-search-input"
            id="room-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    {{-- ── Success message ── --}}
    @if($readingSuccess)
        <div class="rw-sm-success-banner" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ __('Utility reading saved.') }}</span>
        </div>
    @endif

    @if($priceSuccess)
        <div class="rw-sm-success-banner" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ __('Room price saved.') }}</span>
        </div>
    @endif

    {{-- ── Popups: all three are Alpine-owned and open instantly with data the
         card already carries (same pattern as the invoice list's pay modal).
         The only server round-trips are the Save / Reset-login calls. ── --}}

    {{-- Edit room price --}}
    <div
        x-cloak
        x-show="priceOpen"
        class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 px-4 py-4"
        @keydown.escape.window="priceOpen = false"
        id="room-edit-price-popup"
    >
        <div class="rw-sm-modal w-full max-w-sm" @click.outside="priceOpen = false">
            <h3 class="rw-sm-modal-title">{{ __('Edit room price') }}</h3>
            <p class="rw-sm-modal-sub">{{ __('Room') }} <span x-text="price.room"></span></p>

            <div class="mt-4">
                <label class="rw-sm-label" for="room-price-input">
                    {{ __('Monthly rent') }}
                    <span class="text-gray-400" x-show="price.currency" x-text="'(' + price.currency + ')'"></span>
                </label>
                <input type="number" id="room-price-input" x-model="price.value" step="0.01" min="0" class="rw-sm-input" placeholder="0.00">
                @error('priceValue') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex gap-3">
                <button type="button" @click="priceOpen = false" class="rw-sm-btn-secondary flex-1" id="room-price-cancel-btn">{{ __('Cancel') }}</button>
                <button
                    type="button"
                    @click="$wire.submitEditPriceFor(price.id, String(price.value ?? ''))"
                    wire:loading.attr="disabled"
                    wire:target="submitEditPriceFor"
                    class="rw-sm-btn-primary flex-1"
                    id="room-price-submit-btn"
                >
                    <span wire:loading.remove wire:target="submitEditPriceFor">{{ __('Save') }}</span>
                    <span wire:loading wire:target="submitEditPriceFor">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Set utility reading (baseline; metered utilities are property-wide so
         the form is rendered once and reused for every room) --}}
    <div
        x-cloak
        x-show="readingOpen"
        class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 px-4 py-4"
        @keydown.escape.window="readingOpen = false"
        id="room-utility-reading-popup"
    >
        <div class="rw-sm-modal w-full max-w-sm" @click.outside="readingOpen = false">
            <h3 class="rw-sm-modal-title">{{ __('Set utility reading') }}</h3>
            <p class="rw-sm-modal-sub">{{ __('Room') }} <span x-text="reading.room"></span> &middot; {{ __('Record the starting meter reading for this unit\'s metered utilities.') }}</p>

            @if($meteredUtilities->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('This property has no metered utilities set up yet.') }}
                </p>
                <button type="button" @click="readingOpen = false" class="rw-sm-btn-secondary w-full mt-5">{{ __('Cancel') }}</button>
            @else
                <div class="mt-4 space-y-3">
                    @foreach($meteredUtilities as $utility)
                        <div>
                            <label class="rw-sm-label" for="utility-reading-{{ $utility->id }}">
                                {{ \App\Filament\Resources\PropertyUtilityResource::utilityLabel($utility->name) }}
                                @if($utility->unit_of_measure)
                                    <span class="text-gray-400">({{ $utility->unit_of_measure }})</span>
                                @endif
                            </label>
                            <input
                                type="number"
                                id="utility-reading-{{ $utility->id }}"
                                x-model="reading.values['{{ $utility->id }}']"
                                step="0.001"
                                min="0"
                                class="rw-sm-input"
                                placeholder="0.000"
                            >
                            @error('readingValues.'.$utility->id) <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    @error('readingValues') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-5 flex gap-3">
                    <button type="button" @click="readingOpen = false" class="rw-sm-btn-secondary flex-1" id="utility-reading-cancel-btn">{{ __('Cancel') }}</button>
                    <button
                        type="button"
                        @click="$wire.submitUtilityReadingFor(reading.id, reading.values)"
                        wire:loading.attr="disabled"
                        wire:target="submitUtilityReadingFor"
                        class="rw-sm-btn-primary flex-1"
                        id="utility-reading-submit-btn"
                    >
                        <span wire:loading.remove wire:target="submitUtilityReadingFor">{{ __('Save') }}</span>
                        <span wire:loading wire:target="submitUtilityReadingFor">{{ __('Saving…') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Tenant detail / login --}}
    <div
        x-cloak
        x-show="tenantOpen"
        class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
        @keydown.escape.window="tenantOpen = false"
        id="room-tenant-view-popup"
    >
        <div class="rw-sm-modal w-full max-w-md mt-6" @click.outside="tenantOpen = false">
            <h3 class="rw-sm-modal-title" x-text="tenant.name || @js(__('Tenant'))"></h3>
            <p class="rw-sm-modal-sub">{{ __('Room') }} <span x-text="tenant.room"></span></p>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <template x-if="tenant.phone"><div>
                    <p class="rw-sm-detail-label">{{ __('Phone') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.phone"></p>
                </div></template>
                <template x-if="tenant.idCard"><div>
                    <p class="rw-sm-detail-label">{{ __('ID card') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.idCard"></p>
                </div></template>
                <template x-if="tenant.rent"><div>
                    <p class="rw-sm-detail-label">{{ __('Monthly rent') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.rent"></p>
                </div></template>
                <template x-if="tenant.moveIn"><div>
                    <p class="rw-sm-detail-label">{{ __('Move-in') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.moveIn"></p>
                </div></template>
                <template x-if="tenant.address"><div class="col-span-2">
                    <p class="rw-sm-detail-label">{{ __('Address') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.address"></p>
                </div></template>
                <template x-if="tenant.emergency"><div class="col-span-2">
                    <p class="rw-sm-detail-label">{{ __('Emergency contact') }}</p>
                    <p class="rw-sm-detail-value" x-text="tenant.emergency"></p>
                </div></template>
            </div>

            <template x-if="tenant.idCards && tenant.idCards.length">
                <div class="mt-4">
                    <p class="rw-sm-detail-label mb-2">{{ __('ID card photos') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="url in tenant.idCards" :key="url">
                            <a :href="url" target="_blank" rel="noopener" class="block h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                <img :src="url" class="h-full w-full object-cover" alt="{{ __('ID card photo') }}">
                            </a>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Portal login --}}
            <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                <p class="rw-sm-label">{{ __('Tenant portal login') }}</p>

                <template x-if="tenant.username">
                    <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-2">
                        <span class="text-sm font-mono text-gray-900 dark:text-white truncate" x-text="tenant.username"></span>
                        <button type="button" @click="copy(tenant.username, 'username')" class="rw-sm-btn-secondary text-xs shrink-0" id="tenant-view-copy-username">
                            <span x-show="copied !== 'username'">{{ __('Copy') }}</span>
                            <span x-show="copied === 'username'" x-cloak>{{ __('Copied!') }}</span>
                        </button>
                    </div>
                </template>

                <template x-if="newPassword">
                    {{-- Shown exactly once, right after (re)generating — it cannot
                         be retrieved again once this popup closes. --}}
                    <div class="mt-3 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-3">
                        <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                            {{ __('New password — copy it now, it will not be shown again.') }}
                        </p>
                        <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-white dark:bg-gray-900 px-3 py-2">
                            <span class="text-sm font-mono text-gray-900 dark:text-white truncate" x-text="newPassword"></span>
                            <button type="button" @click="copy(newPassword, 'password')" class="rw-sm-btn-secondary text-xs shrink-0" id="tenant-view-copy-password">
                                <span x-show="copied !== 'password'">{{ __('Copy') }}</span>
                                <span x-show="copied === 'password'" x-cloak>{{ __('Copied!') }}</span>
                            </button>
                        </div>
                    </div>
                </template>

                <template x-if="! newPassword">
                    <button
                        type="button"
                        @click="resetLogin()"
                        :disabled="resetting"
                        class="rw-sm-btn-ghost text-sm mt-2 w-full"
                        id="tenant-view-reset-login"
                    >
                        <span x-show="! resetting" x-text="tenant.username ? @js(__('Reset password')) : @js(__('Create login'))"></span>
                        <span x-show="resetting" x-cloak>{{ __('Loading…') }}</span>
                    </button>
                </template>
            </div>

            {{-- Footer actions --}}
            <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 flex gap-3">
                <button type="button" @click="tenantOpen = false" class="rw-sm-btn-secondary flex-1" id="room-tenant-view-cancel-btn">{{ __('Cancel') }}</button>
                <button
                    type="button"
                    @click="tenantOpen = false; $wire.editTenant(tenant.id)"
                    class="rw-sm-btn-primary flex-1"
                    id="room-tenant-view-edit-btn"
                >{{ __('Edit tenant') }}</button>
            </div>
        </div>
    </div>

    {{-- Edit tenant (server-rendered: the edit form is its own Livewire
         component, so this one opens on the round-trip that sets editingRentalId) --}}
    @if($editingRentalId)
        <div
            class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
            @keydown.escape.window="$wire.closeEditTenant()"
            @tenant-edit-cancel.window="$wire.closeEditTenant()"
            id="room-tenant-edit-popup"
        >
            <div class="rw-sm-modal w-full max-w-md mt-6" @click.outside="$wire.closeEditTenant()">
                @livewire(\App\Livewire\SimpleEditTenant::class, ['rentalId' => $editingRentalId], key('simple-room-edit-tenant-'.$editingRentalId))
            </div>
        </div>
    @endif

    {{-- ── Room list ── --}}
    @forelse($rooms as $room)
        @php
            $statusLabel = $room->status->getLabel();
            $statusColor = match($room->status->getColor()) {
                'success' => 'rw-sm-badge-success',
                'info'    => 'rw-sm-badge-info',
                'warning' => 'rw-sm-badge-warning',
                default   => 'rw-sm-badge-gray',
            };
            $tenantName = $room->activeRental?->occupant_name ?: ($room->activeRental?->tenant?->name ?? null);
            $rental = $room->activeRental;
            $tenantData = $rental ? [
                'id' => $rental->id,
                'name' => $rental->occupant_name,
                'room' => $room->room_number,
                'phone' => $rental->occupant_phone,
                'idCard' => $rental->occupant_id_card,
                'rent' => $rental->monthly_rent ? \App\Support\Money::format($rental->monthly_rent, $room->property?->currency) : null,
                'moveIn' => $rental->start_date?->format('d M Y'),
                'address' => $rental->occupant_address,
                'emergency' => trim(($rental->emergency_contact_name ?? '').($rental->emergency_contact_phone ? ' — '.$rental->emergency_contact_phone : '')) ?: null,
                'idCards' => $rental->getMedia('id_cards')->map->getUrl()->values()->all(),
                'username' => $rental->tenant?->username,
            ] : null;
        @endphp

        <div class="rw-sm-invoice-card" id="room-card-{{ $room->id }}">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="rw-sm-room-number">{{ $room->room_number }}</p>
                    @if($tenantName)
                        <p class="rw-sm-tenant-name">{{ $tenantName }}</p>
                    @endif
                    @if($room->activeRental?->monthly_rent)
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ \App\Support\Money::format($room->activeRental->monthly_rent, $room->property?->currency) }}/{{ __('mo') }}
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="rw-sm-badge {{ $statusColor }}">{{ $statusLabel }}</span>

                    {{-- Row action menu (mirrors the desktop table's ActionGroup) --}}
                    <div class="rw-sm-menu" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button
                            type="button"
                            @click="open = ! open"
                            class="rw-sm-menu-btn"
                            id="room-actions-btn-{{ $room->id }}"
                            aria-label="{{ __('Actions') }}"
                            :aria-expanded="open"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM10 8.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 15.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity class="rw-sm-menu-panel">
                            @if($room->activeRental)
                                <button
                                    type="button"
                                    @click="open = false; openTenant(@js($tenantData))"
                                    class="rw-sm-menu-item"
                                    id="room-view-tenant-btn-{{ $room->id }}"
                                >{{ __('View tenant') }}</button>
                            @endif
                            <button
                                type="button"
                                @click="open = false; openPrice(@js(['id' => $room->id, 'room' => $room->room_number, 'currency' => $room->property?->currency, 'value' => $room->rent_amount !== null ? (string) $room->rent_amount : '']))"
                                class="rw-sm-menu-item"
                                id="room-edit-price-btn-{{ $room->id }}"
                            >{{ __('Edit room price') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Quick actions --}}
            <div class="mt-3 flex flex-wrap gap-2">
                @if($room->utility_usages_count === 0)
                    {{-- No utility reading recorded yet for this unit — set the
                         initial (baseline) meter reading before billing can start --}}
                    <button
                        type="button"
                        @click="openReading(@js(['id' => $room->id, 'room' => $room->room_number]))"
                        class="rw-sm-btn-ghost text-sm"
                        id="room-set-utility-reading-{{ $room->id }}"
                    >{{ __('Set utility reading') }}</button>

                @endif

                @if($room->status === \App\Enums\UnitStatus::Available)
                    {{-- Add tenant — deep-links straight past the room-picker step --}}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'add-tenant', 'unit_id' => $room->id]) }}"
                       class="rw-sm-btn-primary text-sm"
                       id="room-add-tenant-{{ $room->id }}"
                    >{{ __('Add tenant') }}</a>
                @elseif($room->status === \App\Enums\UnitStatus::Occupied)
                    {{-- End tenancy — deep-links straight past the room-picker step --}}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'end-tenancy', 'unit_id' => $room->id]) }}"
                       class="rw-sm-btn-warning text-sm"
                       id="room-end-tenancy-{{ $room->id }}"
                    >{{ __('End tenancy') }}</a>
                @endif
            </div>
        </div>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No rooms found.') }}</p>
        </div>
    @endforelse
</div>
