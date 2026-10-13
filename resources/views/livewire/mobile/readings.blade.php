@php
    use App\Services\MeterUtilities;
    use App\Support\ActiveProperty;
    use App\Support\UtilityCatalog;

    $doneCount = count($pending) - $dueCount;
    $filters = ['due' => __('Due').' ('.$dueCount.')', 'done' => __('Done').' ('.$doneCount.')', 'all' => __('All')];
    $lastMeter = $meteredUtilities->last();
@endphp

<div
    x-data="rwMobileReadings(@js($dueCount > 0 ? 'due' : 'all'), @js(['id' => $recordingUnitId, 'room' => $recordingUnit?->room_number ?? '']))"
    @if ($recordingUnitId) x-init="$nextTick(() => $dispatch('rw-m-sheet-open', { name: 'reading' }))" @endif
    x-on:rw-m-sheet-closed.window="$event.detail.name === 'reading' && $wire.recordingUnitId && $wire.closeUtilityReading()"
>
    <a href="{{ route('mobile.settings') }}" wire:navigate class="-ml-2 inline-flex min-h-11 items-center gap-1 pt-3 pr-3 pl-1 font-semibold text-brand-600 active:opacity-60 dark:text-emerald-400" id="rw-m-readings-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        {{ __('Settings') }}
    </a>

    <h1 class="rw-m-title pt-1 pb-0">{{ __('Meter readings') }}</h1>
    <p class="pb-3 text-sm text-slate-500 dark:text-slate-400">{{ now()->translatedFormat('F Y') }}</p>

    @if (! ActiveProperty::id())
        <div class="rw-m-card text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
        </div>
    @elseif ($rooms->isEmpty())
        <div class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400">{{ __('No rooms found.') }}</div>
    @elseif ($propertyMeters->isEmpty())
        <div class="rw-m-card py-8 text-center" id="rw-m-readings-no-meters">
            <p class="text-slate-500 dark:text-slate-400">{{ __('This property has no metered utilities set up yet.') }}</p>
            <a href="{{ route('mobile.utility-rates') }}" wire:navigate class="rw-m-btn rw-m-btn-primary mt-4" id="rw-m-readings-setup">{{ __('Utility Rates') }}</a>
        </div>
    @else
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Filter') }}">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    x-on:click="filter = @js($value)"
                    class="rw-m-pill"
                    x-bind:aria-pressed="filter === @js($value) ? 'true' : 'false'"
                    id="rw-m-readings-filter-{{ $value }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="relative mt-3">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input type="search" x-model="q" placeholder="{{ __('Room number…') }}" class="rw-m-input pl-11" id="rw-m-readings-search" enterkeyhint="search" autocomplete="off">
        </div>

        <div class="rw-m-card mt-4 divide-y divide-slate-100 overflow-hidden p-0 dark:divide-slate-800" x-show="! noMatches()">
            @foreach ($rooms as $room)
                @php
                    $due = $pending[$room->id] ?? 0;
                    $tenant = $room->activeRental?->occupant_name ?? __('No tenant');
                @endphp
                <button
                    type="button"
                    wire:key="rw-m-reading-room-{{ $room->id }}"
                    x-on:click="openRoom(@js(['id' => $room->id, 'room' => $room->room_number, 'tenant' => $tenant]))"
                    class="flex min-h-14 w-full items-center gap-3 px-4 py-2.5 text-left transition-colors active:bg-slate-100 dark:active:bg-slate-800"
                    id="rw-m-reading-room-{{ $room->id }}"
                    data-rw-m-item
                    data-status="{{ $due > 0 ? 'due' : 'done' }}"
                    data-q="{{ mb_strtolower($room->room_number.' '.$tenant) }}"
                    x-show="matches($el)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-bold">{{ $room->room_number }}</span>
                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $tenant }}</span>
                    </span>
                    @if ($due > 0)
                        <span class="rw-m-badge shrink-0 bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ __(':count utility reading(s) due', ['count' => $due]) }}</span>
                    @else
                        <span class="rw-m-badge shrink-0 gap-1 bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-3.5" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                            {{ __('Done') }}
                        </span>
                    @endif
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-300 dark:text-slate-600" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </button>
            @endforeach
        </div>

        <div x-cloak x-show="noMatches()" class="rw-m-card mt-4 py-10 text-center text-slate-500 dark:text-slate-400" id="rw-m-readings-empty">
            <p x-show="filter === 'due' && q.trim() === ''">{{ __('Every room has this month\'s readings.') }}</p>
            <p x-show="filter !== 'due' || q.trim() !== ''">{{ __('No rooms found.') }}</p>
        </div>
    @endif

    {{-- ── Record readings for one room ── --}}
    <x-mobile.sheet name="reading" :label="__('Record meter reading')">
        <div class="px-5 pt-2">
            <h2 class="text-lg font-bold">
                {{ __('Record meter reading') }} ·
                <span x-text="room.room">{{ $recordingUnit?->room_number }}</span>
            </h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __("Enter today's meter reading for each metered utility.") }}</p>

            <div wire:loading.block wire:target="openUtilityReading" class="mt-4 space-y-3" aria-busy="true">
                <div class="h-20 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
                <div class="h-20 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
            </div>

            <div wire:loading.remove wire:target="openUtilityReading">
                @if ($recordingUnitId)
                    @if ($meteredUtilities->isEmpty())
                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('This property has no metered utilities set up yet.') }}</p>
                    @else
                        <form class="mt-4 space-y-4" x-on:submit.prevent="$wire.submitUtilityReading()" id="rw-m-reading-form">
                            @foreach ($meteredUtilities as $utility)
                                @php $last = $utility->is($lastMeter); @endphp
                                <div wire:key="rw-m-reading-{{ $recordingUnitId }}-{{ $utility->id }}">
                                    <label for="rw-m-reading-{{ $utility->id }}" class="mb-1 block font-semibold">
                                        {{ UtilityCatalog::label($utility->name) }}
                                        @if ($utility->unit_of_measure)<span class="font-normal text-slate-400">({{ $utility->unit_of_measure }})</span>@endif
                                    </label>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        id="rw-m-reading-{{ $utility->id }}"
                                        wire:model="readingValues.{{ $utility->id }}"
                                        class="rw-m-input py-4 text-2xl font-semibold tabular-nums"
                                        placeholder="0"
                                        autocomplete="off"
                                        data-rw-m-reading
                                        enterkeyhint="{{ $last ? 'done' : 'next' }}"
                                        @unless ($last) x-on:keydown.enter.prevent="focusNext($el)" @endunless
                                    >
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ MeterUtilities::readingHint($recordingUnitId, $utility->id, $utility->unit_of_measure) }}</p>
                                    @error('readingValues.'.$utility->id) <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            @endforeach

                            @error('readingValues') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'reading' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-reading-cancel">{{ __('Cancel') }}</button>
                                <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="submitUtilityReading" id="rw-m-reading-save">
                                    <span wire:loading.remove wire:target="submitUtilityReading">{{ __('Save') }}</span>
                                    <span wire:loading wire:target="submitUtilityReading">{{ __('Saving…') }}</span>
                                </button>
                            </div>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </x-mobile.sheet>
</div>
