@php
    use App\Services\MeterUtilities;
    use App\Support\UtilityCatalog;
@endphp

<div id="m2-add-tenant" class="m2a">
    <x-m2.header :title="__('Add tenant')" :subtitle="__('Room').' '.$unit->room_number" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main m2a-stack">
        @if ($step === 'done' && $result)
            <div class="m2a-card" id="m2-add-tenant-done" style="text-align:center" data-m2-feedback="big" data-m2-feedback-message="{{ __('Tenancy created') }}">
                <x-m2.icon name="check" style="margin:0 auto;width:48px;height:48px" />
                <b>{{ __('Tenancy created') }}</b>
                <p class="m2-muted" style="margin:0">{{ $result['occupant_name'] }}</p>
                @if ($result['username'])<p style="margin:0">{{ __('Login username') }}: <b id="m2-add-tenant-username">{{ $result['username'] }}</b></p>@endif
                @if ($result['password'])
                    <p style="margin:0">{{ __('Login password') }}: <b id="m2-add-tenant-password">{{ $result['password'] }}</b></p>
                    <p class="m2-muted m2-sm" style="margin:0">{{ __('Save this password now — it is shown only once.') }}</p>
                @endif
            </div>
            <a href="{{ route('m2.tenant', $result['rental_id']) }}" wire:navigate class="m2-btn">{{ __('View tenant') }}</a>
            <a href="{{ route('m2.room', $unit) }}" wire:navigate class="m2-btn is-ghost">{{ __('Back to room') }}</a>
        @elseif (! $available)
            <div class="m2a-empty" id="m2-add-tenant-unavailable">{{ __('This room is not available for a new tenant.') }}</div>
            <a href="{{ route('m2.room', $unit) }}" wire:navigate class="m2-btn is-ghost">{{ __('Back to room') }}</a>
        @else
            <form wire:submit="submit" class="m2a-stack" id="m2-add-tenant-form" novalidate>
                @include('livewire.m2.partials.tenant-fields', ['idp' => 'm2-at'])

                <h2 class="m2a-h">{{ __('ID card photos') }}</h2>
                <x-m2.id-photos idp="m2-at-photo" :photos="$idCardPhotos" target="newIdCardPhotos" />

                <h2 class="m2a-h">{{ __('Rent') }}</h2>
                <div class="m2a-card">
                    <div>
                        <label for="m2-at-start" class="m2-label">{{ __('Start date') }} *</label>
                        <input type="date" id="m2-at-start" wire:model="startDate" class="m2-input">
                        @error('startDate')<p class="m2-err">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="m2-at-rent" class="m2-label">{{ __('Monthly rent') }} *</label>
                        <input type="text" inputmode="decimal" id="m2-at-rent" wire:model="monthlyRent" class="m2-input">
                        @error('monthlyRent')<p class="m2-err">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="m2-at-deposit" class="m2-label">{{ __('Security deposit') }}</label>
                        <input type="text" inputmode="decimal" id="m2-at-deposit" wire:model="securityDeposit" class="m2-input">
                        @error('securityDeposit')<p class="m2-err">{{ $message }}</p>@enderror
                    </div>
                </div>

                @if ($meteredUtilities->isNotEmpty())
                    <div class="m2a-card" id="m2-at-readings">
                        <span class="m2-label">{{ __('Move-in meter readings') }} ({{ __('optional') }})</span>
                        @foreach ($meteredUtilities as $utility)
                            <div>
                                <label for="m2-at-reading-{{ $utility->id }}" class="m2-label">{{ UtilityCatalog::label($utility->name) }}@if ($utility->unit_of_measure) ({{ $utility->unit_of_measure }})@endif</label>
                                <input type="text" inputmode="decimal" id="m2-at-reading-{{ $utility->id }}" wire:model="moveInReadings.{{ $utility->id }}" class="m2-input">
                                <p class="m2-muted m2-sm" style="margin:4px 0 0">{{ MeterUtilities::readingHint($unit->id, $utility->id, $utility->unit_of_measure) }}</p>
                                @error("moveInReadings.{$utility->id}")<p class="m2-err">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                @endif

                @error('unitId')<p class="m2-err" role="alert">{{ $message }}</p>@enderror

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="submit,newIdCardPhotos" id="m2-add-tenant-submit">{{ __('Confirm') }}</button>
            </form>
        @endif
    </main>
</div>
