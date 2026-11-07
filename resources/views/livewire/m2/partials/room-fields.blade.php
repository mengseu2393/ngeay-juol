{{-- A room's own details: the same fields on Add room and Edit room. --}}
<div class="m2a-card">
    <div>
        <label for="m2-room-number" class="m2-label">{{ __('Room number') }} *</label>
        <input type="text" id="m2-room-number" wire:model="roomNumber" class="m2-input" autocomplete="off" autocapitalize="characters">
        @error('roomNumber')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div class="m2-grid2">
        <div>
            <label for="m2-room-floor" class="m2-label">{{ __('Floor') }}</label>
            <input type="text" id="m2-room-floor" wire:model="floorNumber" class="m2-input" inputmode="numeric" autocomplete="off">
            @error('floorNumber')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="m2-room-occupants" class="m2-label">{{ __('Max occupants') }}</label>
            <input type="number" id="m2-room-occupants" wire:model="maxOccupants" class="m2-input" inputmode="numeric" min="1" max="20" autocomplete="off">
            @error('maxOccupants')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
    </div>
    <div>
        <label for="m2-room-type" class="m2-label">{{ __('Room type') }} *</label>
        <input type="text" id="m2-room-type" wire:model="roomType" class="m2-input" list="m2-room-types" autocomplete="off" placeholder="{{ __('e.g. Standard') }}">
        <datalist id="m2-room-types">
            @foreach ($roomTypes as $type)
                <option value="{{ $type }}"></option>
            @endforeach
        </datalist>
        @error('roomType')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
</div>
