{{-- The tenant's own details and emergency contact: the same fields on Add tenant and Edit tenant. $idp prefixes the input ids. --}}
<div class="m2a-card">
    <div>
        <label for="{{ $idp }}-name" class="m2-label">{{ __('Full name') }} *</label>
        <input type="text" id="{{ $idp }}-name" wire:model="occupantName" class="m2-input" placeholder="{{ __('Tenant full name') }}" autocomplete="name">
        @error('occupantName')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="{{ $idp }}-phone" class="m2-label">{{ __('Phone') }}</label>
        <input type="tel" id="{{ $idp }}-phone" wire:model="occupantPhone" class="m2-input" inputmode="tel" placeholder="012 345 678" autocomplete="tel">
        @error('occupantPhone')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="{{ $idp }}-id" class="m2-label">{{ __('ID card') }}</label>
        <input type="text" id="{{ $idp }}-id" wire:model="occupantIdCard" class="m2-input" placeholder="{{ __('ID card number') }}" autocomplete="off">
        @error('occupantIdCard')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div class="m2-grid2">
        <div>
            <label for="{{ $idp }}-gender" class="m2-label">{{ __('Gender') }}</label>
            <select id="{{ $idp }}-gender" wire:model="occupantGender" class="m2-input">
                <option value="">{{ __('Select gender') }}</option>
                <option value="male">{{ __('Male') }}</option>
                <option value="female">{{ __('Female') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
            @error('occupantGender')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="{{ $idp }}-dob" class="m2-label">{{ __('Date of birth') }}</label>
            <input type="date" id="{{ $idp }}-dob" wire:model="occupantDob" class="m2-input" max="{{ now()->toDateString() }}" autocomplete="bday">
            @error('occupantDob')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
    </div>
    <div>
        <label for="{{ $idp }}-nationality" class="m2-label">{{ __('Nationality') }}</label>
        <input type="text" id="{{ $idp }}-nationality" wire:model="occupantNationality" class="m2-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}" autocomplete="off">
        @error('occupantNationality')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="{{ $idp }}-workplace" class="m2-label">{{ __('Workplace') }}</label>
        <input type="text" id="{{ $idp }}-workplace" wire:model="occupantWorkplace" class="m2-input" placeholder="{{ __('e.g. company name') }}" autocomplete="organization">
        @error('occupantWorkplace')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="{{ $idp }}-address" class="m2-label">{{ __('Address') }}</label>
        <textarea id="{{ $idp }}-address" wire:model="occupantAddress" class="m2-input m2a-area" rows="2" autocomplete="street-address"></textarea>
        @error('occupantAddress')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
</div>

<h2 class="m2a-h">{{ __('Emergency contact') }}</h2>
<div class="m2a-card">
    <div>
        <label for="{{ $idp }}-ec-name" class="m2-label">{{ __('Name') }}</label>
        <input type="text" id="{{ $idp }}-ec-name" wire:model="emergencyContactName" class="m2-input" autocomplete="off">
        @error('emergencyContactName')<p class="m2-err">{{ $message }}</p>@enderror
    </div>
    <div class="m2-grid2">
        <div>
            <label for="{{ $idp }}-ec-phone" class="m2-label">{{ __('Phone') }}</label>
            <input type="tel" id="{{ $idp }}-ec-phone" wire:model="emergencyContactPhone" class="m2-input" inputmode="tel" autocomplete="off">
            @error('emergencyContactPhone')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="{{ $idp }}-ec-relationship" class="m2-label">{{ __('Relationship') }}</label>
            <input type="text" id="{{ $idp }}-ec-relationship" wire:model="emergencyContactRelationship" class="m2-input" placeholder="{{ __('e.g. mother, brother') }}" autocomplete="off">
            @error('emergencyContactRelationship')<p class="m2-err">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
