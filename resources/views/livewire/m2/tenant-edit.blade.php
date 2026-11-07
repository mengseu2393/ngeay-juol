@php
    $symbol = \App\Support\Money::symbol(\App\Support\Money::forRecord($rental));
@endphp

<div id="m2-tenant-edit" class="m2a">
    <x-m2.header
        :title="__('Edit tenant')"
        :subtitle="$rental->unit ? __('Room').' '.$rental->unit->room_number : null"
        :back="route('m2.tenant', $rental)"
    />

    <main class="m2-wrap m2a-main m2a-stack">
        <form wire:submit="submit" class="m2a-stack" id="m2-tenant-edit-form" novalidate>
            @include('livewire.m2.partials.tenant-fields', ['idp' => 'm2-et'])

            <h2 class="m2a-h">{{ __('ID card photos') }}</h2>
            <x-m2.id-photos idp="m2-et-photo" :saved="$savedPhotos" :photos="$idCardPhotos" target="idCardPhotos" :append="true" />

            <h2 class="m2a-h">{{ __('Rent') }}</h2>
            <div class="m2a-card">
                <div class="m2-grid2">
                    <div>
                        <label for="m2-et-rent" class="m2-label">{{ __('Monthly rent') }} ({{ $symbol }}) *</label>
                        <input type="text" inputmode="decimal" id="m2-et-rent" wire:model="monthlyRent" class="m2-input" autocomplete="off">
                        @error('monthlyRent')<p class="m2-err">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="m2-et-deposit" class="m2-label">{{ __('Security deposit') }} ({{ $symbol }})</label>
                        <input type="text" inputmode="decimal" id="m2-et-deposit" wire:model="securityDeposit" class="m2-input" autocomplete="off">
                        @error('securityDeposit')<p class="m2-err">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="submit,idCardPhotos" id="m2-tenant-edit-save">
                <span wire:loading.remove wire:target="submit">{{ __('Save changes') }}</span>
                <span wire:loading wire:target="submit">{{ __('Saving…') }}</span>
            </button>
        </form>
    </main>
</div>
