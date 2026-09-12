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
    @if($settingReadingUnitId)
        <div
            x-data
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
            @keydown.escape.window="$wire.closeUtilityReading()"
        >
            <div class="relative w-full max-w-md mt-6" @click.outside="$wire.closeUtilityReading()">
                <button
                    type="button"
                    wire:click="closeUtilityReading"
                    class="rw-sm-modal-close-btn"
                    id="utility-reading-close-btn"
                    aria-label="{{ __('Close') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>

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
                                        {{ $utility->name }}
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

                        <div class="mt-5 flex gap-3">
                            <button type="button" wire:click="closeUtilityReading" class="rw-sm-btn-secondary flex-1" id="utility-reading-cancel-btn">{{ __('Cancel') }}</button>
                            <button
                                type="button"
                                wire:click="submitUtilityReading"
                                wire:loading.attr="disabled"
                                wire:target="submitUtilityReading"
                                class="rw-sm-btn-primary flex-1"
                                id="utility-reading-submit-btn"
                            >
                                <span wire:loading.remove wire:target="submitUtilityReading">{{ __('Save') }}</span>
                                <span wire:loading wire:target="submitUtilityReading">{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    @endif
                </div>
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
                @if($room->utility_usages_count === 0)
                    {{-- No utility reading recorded yet for this unit — set the
                         initial (baseline) meter reading before billing can start --}}
                    <button
                        type="button"
                        wire:click="openUtilityReading({{ $room->id }})"
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
