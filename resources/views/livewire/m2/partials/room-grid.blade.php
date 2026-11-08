{{--
    Room generator + editable room list, the setup wizard's "Rooms & prices" in /m2's look. Used by
    /m2/setup (once per property) and /m2's Add property. Floors × rooms per floor at one price →
    "Generate rooms" → a list where any room's number or price can be changed, removed or added.

    Expects: $key (id prefix), $floors / $perFloor / $price / $gridModel (wire:model paths),
    $grid (the rows), $symbol (currency), $pid (the property id passed to the methods, or null).
    Methods on the component: generateRooms([pid]), addRoom([pid]), removeRoom([pid,] index).
--}}
@php
    $arg = $pid !== null ? $pid : '';
    $argComma = $pid !== null ? $pid.', ' : '';
@endphp

<div class="m2a-card m2-rg" id="{{ $key }}-rooms">
    <div class="m2a-two">
        <div>
            <label for="{{ $key }}-floors" class="m2-label">{{ __('Floors') }}</label>
            <input type="number" id="{{ $key }}-floors" wire:model="{{ $floors }}" class="m2-input" inputmode="numeric" min="1" max="50" autocomplete="off">
            @error($floors) <p class="m2-err">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="{{ $key }}-per-floor" class="m2-label">{{ __('Rooms per floor') }}</label>
            <input type="number" id="{{ $key }}-per-floor" wire:model="{{ $perFloor }}" class="m2-input" inputmode="numeric" min="1" max="100" autocomplete="off">
            @error($perFloor) <p class="m2-err">{{ $message }}</p> @enderror
        </div>
    </div>
    <div>
        <label for="{{ $key }}-price" class="m2-label">{{ __('Price per room') }} ({{ $symbol }})</label>
        <input type="text" id="{{ $key }}-price" wire:model="{{ $price }}" class="m2-input" inputmode="decimal" autocomplete="off" placeholder="0">
        @error($price) <p class="m2-err">{{ $message }}</p> @enderror
    </div>

    <button type="button" class="m2-btn is-ghost" wire:click="generateRooms({{ $arg }})" wire:loading.attr="disabled" wire:target="generateRooms" id="{{ $key }}-generate">
        {{ count($grid) ? __('Regenerate rooms') : __('Generate rooms') }}
    </button>
    @if (count($grid))
        <p class="m2-muted m2-sm">{{ __('Regenerating keeps any price you changed by hand.') }}</p>
    @endif
    @error($gridModel) <p class="m2-err">{{ $message }}</p> @enderror
</div>

@if (count($grid))
    <div class="m2a-list m2-rg-list" id="{{ $key }}-room-list">
        <div class="m2-rg-head">
            <span>{{ __('Room number') }}</span>
            <span>{{ __('Price') }} ({{ $symbol }})</span>
            <b>{{ __(':count rooms', ['count' => count($grid)]) }}</b>
        </div>
        @foreach ($grid as $k => $room)
            <div class="m2-rg-row" wire:key="{{ $key }}-room-{{ $k }}-{{ $room['id'] ?? 'new' }}">
                <input type="text" class="m2-input" aria-label="{{ __('Room number') }}" wire:model="{{ $gridModel }}.{{ $k }}.number" placeholder="{{ __('Room') }}" autocomplete="off">
                <input type="text" class="m2-input" aria-label="{{ __('Price') }}" wire:model="{{ $gridModel }}.{{ $k }}.price" inputmode="decimal" placeholder="0" autocomplete="off">
                <button type="button" class="m2-rg-remove" aria-label="{{ __('Remove') }}" wire:click="removeRoom({{ $argComma }}{{ $k }})">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
                @error("$gridModel.$k.number") <p class="m2-err">{{ $message }}</p> @enderror
                @error("$gridModel.$k.price") <p class="m2-err">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>
@endif

<button type="button" class="m2-rg-add" wire:click="addRoom({{ $arg }})" id="{{ $key }}-add-room">+ {{ __('Add a room') }}</button>
