<div class="space-y-4">

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Room number…') }}"
            class="rw-sm-search-input"
            id="utility-usage-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    {{-- ── Success message ── --}}
    @if($readingSuccess)
        <div class="rw-sm-success-banner" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ __('Meter reading saved.') }}</span>
        </div>
    @endif

    {{-- ── Record meter reading reveal — ongoing monthly reading with real
         consumption computed via MeterReadingResolver (not the baseline-only
         "initial setup" flow SimpleRoomList uses for a room's first reading) ── --}}
    @if($recordingUnitId)
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
                    id="utility-usage-close-btn"
                    aria-label="{{ __('Close') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>

                <div class="rw-sm-modal w-full">
                    <h3 class="rw-sm-modal-title">{{ __('Record meter reading') }}</h3>
                    <p class="rw-sm-modal-sub">{{ __("Enter today's meter reading for each metered utility.") }}</p>

                    @if($meteredUtilities->isEmpty())
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('This property has no metered utilities set up yet.') }}
                        </p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach($meteredUtilities as $utility)
                                <div>
                                    <label class="rw-sm-label" for="utility-usage-{{ $utility->id }}">
                                        {{ $utility->name }}
                                        @if($utility->unit_of_measure)
                                            <span class="text-gray-400">({{ $utility->unit_of_measure }})</span>
                                        @endif
                                    </label>
                                    <input
                                        type="number"
                                        id="utility-usage-{{ $utility->id }}"
                                        wire:model="readingValues.{{ $utility->id }}"
                                        step="0.001"
                                        min="0"
                                        class="rw-sm-input"
                                        placeholder="0.000"
                                    >
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        {{ \App\Filament\Resources\UnitResource::readingHint($recordingUnitId, $utility->id, $utility->unit_of_measure) }}
                                    </p>
                                </div>
                            @endforeach
                            @error('readingValues') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-5 flex gap-3">
                            <button type="button" wire:click="closeUtilityReading" class="rw-sm-btn-secondary flex-1" id="utility-usage-cancel-btn">{{ __('Cancel') }}</button>
                            <button
                                type="button"
                                wire:click="submitUtilityReading"
                                wire:loading.attr="disabled"
                                wire:target="submitUtilityReading"
                                class="rw-sm-btn-primary flex-1"
                                id="utility-usage-submit-btn"
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
            $pendingCount = \App\Filament\Resources\UnitResource::meterUtilitiesFor($room)->count();
        @endphp

        <button
            type="button"
            wire:click="openUtilityReading({{ $room->id }})"
            wire:loading.attr="disabled"
            wire:target="openUtilityReading({{ $room->id }})"
            class="rw-sm-room-row w-full text-left"
            id="utility-usage-room-{{ $room->id }}"
        >
            <div class="flex items-center justify-between">
                <div>
                    <p class="rw-sm-room-number">{{ $room->room_number }}</p>
                </div>
                <span
                    wire:loading.remove
                    wire:target="openUtilityReading({{ $room->id }})"
                    class="rw-sm-badge {{ $pendingCount > 0 ? 'rw-sm-badge-warning' : 'rw-sm-badge-gray' }} shrink-0"
                >
                    @if($pendingCount > 0)
                        {{ __(':count utility reading(s) due', ['count' => $pendingCount]) }}
                    @else
                        {{ __('No metered utilities') }}
                    @endif
                </span>
                <span
                    wire:loading.flex
                    wire:target="openUtilityReading({{ $room->id }})"
                    class="rw-sm-badge rw-sm-badge-gray shrink-0 items-center gap-1.5"
                    style="display: none;"
                >
                    <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    {{ __('Loading…') }}
                </span>
            </div>
        </button>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No rooms found.') }}</p>
        </div>
    @endforelse
</div>
