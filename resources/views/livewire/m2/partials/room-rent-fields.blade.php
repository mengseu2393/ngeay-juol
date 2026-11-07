{{-- A room's price: the same fields on Add room and Edit room. --}}
<div class="m2-grid2">
    <div>
        <label for="m2-room-rent" class="m2-label">{{ __('Monthly rent') }} *</label>
        <input type="text" id="m2-room-rent" wire:model="rentAmount" class="m2-input" inputmode="decimal" autocomplete="off">
        @error('rentAmount')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="m2-room-currency" class="m2-label">{{ __('Currency') }}</label>
        <select id="m2-room-currency" wire:model="rentCurrency" class="m2-input">
            <option value="USD">{{ __('US Dollar ($)') }}</option>
            <option value="KHR">{{ __('Khmer Riel (៛)') }}</option>
        </select>
        @error('rentCurrency')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
</div>
