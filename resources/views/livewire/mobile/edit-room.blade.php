@php
    use App\Services\MeterUtilities;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $label = 'mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $heading = 'px-1 pb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400';
@endphp

<div x-data x-on:rw-m-sheet-closed.window="$event.detail.name === 'room-readings' && $wire.discardReadings()">
    <a href="{{ route('mobile.room', $unit) }}" wire:navigate class="-ml-1 inline-flex min-h-11 items-center gap-1 pt-2 font-semibold text-brand-600 dark:text-emerald-400" id="rw-m-edit-room-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        {{ __('Room :room', ['room' => $unit->room_number]) }}
    </a>
    <h1 class="rw-m-title pt-1">{{ __('Edit room') }}</h1>

    <form wire:submit="save" id="rw-m-edit-room-form" novalidate>
        {{-- ── The room ── --}}
        <section>
            <h2 class="{{ $heading }}">{{ __('Room') }}</h2>
            <div class="rw-m-card space-y-4">
                <div>
                    <label for="rw-m-room-number" class="{{ $label }}">{{ __('Room number') }}</label>
                    <input type="text" id="rw-m-room-number" wire:model="roomNumber" class="rw-m-input" autocomplete="off" autocapitalize="characters" enterkeyhint="next">
                    @error('roomNumber') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-room-floor" class="{{ $label }}">{{ __('Floor') }}</label>
                        <input type="text" id="rw-m-room-floor" wire:model="floorNumber" class="rw-m-input" inputmode="numeric" autocomplete="off" enterkeyhint="next">
                        @error('floorNumber') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-room-occupants" class="{{ $label }}">{{ __('Max occupants') }}</label>
                        <input type="number" id="rw-m-room-occupants" wire:model="maxOccupants" class="rw-m-input" inputmode="numeric" min="1" max="20" autocomplete="off">
                        @error('maxOccupants') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="rw-m-room-type" class="{{ $label }}">{{ __('Room type') }}</label>
                    <input type="text" id="rw-m-room-type" wire:model="roomType" class="rw-m-input" list="rw-m-room-types" autocomplete="off" placeholder="{{ __('e.g. Standard') }}">
                    <datalist id="rw-m-room-types">
                        @foreach ($roomTypes as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>
                    @error('roomType') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- ── Rent ── --}}
        <section class="mt-6">
            <h2 class="{{ $heading }}">{{ __('Rent') }}</h2>
            <div class="rw-m-card space-y-4">
                <div>
                    <label for="rw-m-room-rent" class="{{ $label }}">{{ __('Monthly rent') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 font-semibold text-slate-400" aria-hidden="true" x-text="$wire.rentCurrency === 'KHR' ? '៛' : '$'">{{ Money::symbol($rentCurrency) }}</span>
                        <input type="text" id="rw-m-room-rent" wire:model="rentAmount" class="rw-m-input pl-9" inputmode="decimal" autocomplete="off" enterkeyhint="done">
                    </div>
                    @error('rentAmount') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="{{ $label }}" id="rw-m-room-currency-label">{{ __('Currency') }}</span>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="rw-m-room-currency-label">
                        @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $name)
                            <label class="rw-m-prop-choice">
                                <input type="radio" class="sr-only" value="{{ $code }}" wire:model="rentCurrency" id="rw-m-room-currency-{{ strtolower($code) }}">
                                <span>{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('rentCurrency') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- ── Status & notes ── --}}
        <section class="mt-6">
            <h2 class="{{ $heading }}">{{ __('Status') }}</h2>
            <div class="rw-m-card space-y-4">
                <div>
                    <label for="rw-m-room-status" class="{{ $label }}">{{ __('Room status') }}</label>
                    <select id="rw-m-room-status" wire:model="status" class="rw-m-input">
                        @foreach ($statuses as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @if ($hasTenant)
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Someone lives here. End the tenancy from the room screen to free it.') }}</p>
                    @endif
                    @error('status') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-room-notes" class="{{ $label }}">{{ __('Notes') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                    <textarea id="rw-m-room-notes" wire:model="description" rows="3" class="rw-m-input" autocomplete="off"></textarea>
                    @error('description') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
    </form>

    {{-- ── Readings & danger zone (outside the form: their own actions) ── --}}
    @if ($meterUtilities->isNotEmpty())
        <section class="mt-6">
            <h2 class="{{ $heading }}">{{ __('Meter readings') }}</h2>
            <button
                type="button"
                class="rw-m-card flex min-h-14 w-full items-center justify-between gap-3 text-left active:bg-slate-50 dark:active:bg-slate-800/60"
                x-on:click="$dispatch('rw-m-sheet-open', { name: 'room-readings' })"
                id="rw-m-room-readings-open"
            >
                <span>
                    <span class="block font-semibold">{{ __('Record meter readings') }}</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $meterUtilities->map(fn ($u) => UtilityCatalog::label($u->name))->implode(' · ') }}</span>
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
            </button>
        </section>
    @endif

    @if ($canDelete)
        <section class="mt-6">
            <button
                type="button"
                class="rw-m-btn w-full border border-red-200 bg-white text-red-600 active:bg-red-50 dark:border-red-500/30 dark:bg-slate-900 dark:text-red-400"
                x-on:click="$dispatch('rw-m-sheet-open', { name: 'room-delete' })"
                id="rw-m-room-delete-open"
            >{{ __('Delete room') }}</button>
        </section>
    @endif

    {{-- Room for the sticky Save bar. --}}
    <div class="h-20" aria-hidden="true"></div>

    <div class="rw-m-prop-savebar">
        <div class="mx-auto max-w-2xl">
            <button type="submit" form="rw-m-edit-room-form" class="rw-m-btn rw-m-btn-primary w-full" wire:loading.attr="disabled" wire:target="save" id="rw-m-edit-room-save">
                <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

    {{-- ── Meter readings sheet ── --}}
    @if ($meterUtilities->isNotEmpty())
        <x-mobile.sheet name="room-readings" :label="__('Meter readings')">
            <form class="space-y-4 px-5 pt-2" wire:submit="saveReadings" novalidate>
                <div>
                    <h2 class="text-lg font-bold">{{ __('Meter readings') }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Room :room', ['room' => $unit->room_number]) }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-reading-date" class="{{ $label }}">{{ __('Reading date') }}</label>
                        <input type="date" id="rw-m-reading-date" wire:model="readingDate" max="{{ now()->toDateString() }}" class="rw-m-input">
                        @error('readingDate') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-reading-type" class="{{ $label }}">{{ __('Reading type') }}</label>
                        <select id="rw-m-reading-type" wire:model="readingType" class="rw-m-input">
                            @foreach ($readingTypes as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @foreach ($meterUtilities as $utility)
                    <div wire:key="rw-m-reading-{{ $utility->id }}">
                        <label for="rw-m-reading-{{ $utility->id }}" class="{{ $label }}">
                            {{ UtilityCatalog::label($utility->name) }}@if ($utility->unit_of_measure) <span class="font-normal text-slate-400">({{ $utility->unit_of_measure }})</span>@endif
                        </label>
                        <input type="text" id="rw-m-reading-{{ $utility->id }}" wire:model="meters.{{ $utility->id }}" class="rw-m-input" inputmode="decimal" autocomplete="off" enterkeyhint="next">
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ MeterUtilities::readingHint($unit->id, $utility->id, $utility->unit_of_measure) }}</p>
                        @error('meters.'.$utility->id) <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                @error('meters') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'room-readings' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-readings-cancel">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveReadings" id="rw-m-readings-save">
                        <span wire:loading.remove wire:target="saveReadings">{{ __('Save readings') }}</span>
                        <span wire:loading wire:target="saveReadings">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        </x-mobile.sheet>
    @endif

    {{-- ── Delete confirmation ── --}}
    @if ($canDelete)
        <x-mobile.sheet name="room-delete" :label="__('Delete room')">
            <div class="space-y-4 px-5 pt-2">
                <div>
                    <h2 class="text-lg font-bold">{{ __('Delete room :room?', ['room' => $unit->room_number]) }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('The room disappears from your room list. Its past invoices and readings stay in your records.') }}</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'room-delete' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-room-delete-cancel">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" class="rw-m-btn bg-red-600 text-white active:bg-red-700" id="rw-m-room-delete-confirm">{{ __('Delete') }}</button>
                </div>
            </div>
        </x-mobile.sheet>
    @endif
</div>
