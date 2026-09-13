<div class="space-y-4">

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

    {{-- ── Set utility reading modal (fresh-start baseline reading, one metered
         utility per row; a flat charge needs no meter so isn't listed) ── --}}
    <x-rw-simple-popup name="room-utility-reading" close="closeUtilityReading">
        @if($settingReadingUnitId)
                <div class="rw-sm-modal w-full">
                    <h3 class="rw-sm-modal-title">{{ __('Set utility reading') }}</h3>
                    <p class="rw-sm-modal-sub">{{ __('Record the starting meter reading for this unit\'s metered utilities.') }}</p>

                    @if($meteredUtilities->isEmpty())
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('This property has no metered utilities set up yet.') }}
                        </p>
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
                                        wire:model="readingValues.{{ $utility->id }}"
                                        step="0.001"
                                        min="0"
                                        class="rw-sm-input"
                                        placeholder="0.000"
                                    >
                                </div>
                            @endforeach
                            @error('readingValues') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-5">
                            <button
                                type="button"
                                wire:click="submitUtilityReading"
                                wire:loading.attr="disabled"
                                wire:target="submitUtilityReading"
                                class="rw-sm-btn-primary w-full"
                                id="utility-reading-submit-btn"
                            >
                                <span wire:loading.remove wire:target="submitUtilityReading">{{ __('Save') }}</span>
                                <span wire:loading wire:target="submitUtilityReading">{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    @endif
                </div>
        @endif
    </x-rw-simple-popup>

    {{-- ── Tenant detail / login popup ── --}}
    <x-rw-simple-popup name="room-tenant-view" close="closeTenantView">
        @if($viewingRental)
                <div class="rw-sm-modal w-full" x-data="{
                copied: null,
                copy(text, key) {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copied = key;
                        setTimeout(() => { if (this.copied === key) this.copied = null; }, 1500);
                    });
                },
            }">
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

                    {{-- ── ID card photos (Rental's own `id_cards` media collection —
                         the same one RentalResource's desktop form and the mobile
                         add-tenant upload both use) ── --}}
                    @if($viewingRental->getMedia('id_cards')->isNotEmpty())
                        <div class="mt-4">
                            <p class="rw-sm-detail-label mb-2">{{ __('ID card photos') }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($viewingRental->getMedia('id_cards') as $media)
                                    <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener" class="block h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                        <img src="{{ $media->getUrl() }}" class="h-full w-full object-cover" alt="{{ __('ID card photo') }}">
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

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
        @endif
    </x-rw-simple-popup>

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
                <span class="rw-sm-badge {{ $statusColor }} shrink-0">{{ $statusLabel }}</span>
            </div>

            {{-- Quick actions --}}
            <div class="mt-3 flex flex-wrap gap-2">
                @if($room->activeRental)
                    <button
                        type="button"
                        @click="$dispatch('rw-popup-open', { name: 'room-tenant-view', call: () => $wire.viewTenant({{ $room->activeRental->id }}) })"
                        class="rw-sm-btn-ghost text-sm"
                        id="room-view-tenant-btn-{{ $room->id }}"
                    >{{ __('View tenant') }}</button>
                @endif

                @if($room->utility_usages_count === 0)
                    {{-- No utility reading recorded yet for this unit — set the
                         initial (baseline) meter reading before billing can start --}}
                    <button
                        type="button"
                        @click="$dispatch('rw-popup-open', { name: 'room-utility-reading', call: () => $wire.openUtilityReading({{ $room->id }}) })"
                        class="rw-sm-btn-ghost text-sm"
                        id="room-set-utility-reading-{{ $room->id }}"
                    >{{ __('Set utility reading') }}</button>

                    {{-- Deep-links to the Utility tab, straight into this room's
                         reading form — same shortcut pattern as Add/End tenancy
                         below. Distinct from the button above: that one is this
                         screen's own baseline-only "initial setup" flow (see
                         SimpleUtilityUsage's class docblock), this one goes to
                         the dedicated Utility tab's real consumption math. --}}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'utility', 'unit_id' => $room->id]) }}"
                       class="rw-sm-btn-ghost text-sm"
                       id="room-goto-utility-{{ $room->id }}"
                    >{{ __('Set Utility') }}</a>
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
