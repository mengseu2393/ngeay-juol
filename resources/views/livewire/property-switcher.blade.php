<div class="rw-switcher">
    @if (! $this->isEligible())
        {{-- Unassigned manager: nothing to switch between. --}}
        <p class="rw-switcher__notice">{{ __('No property assigned — contact your administrator.') }}</p>
    @else
        @php($properties = $this->properties())

        @if ($properties->isEmpty())
            {{-- 0 properties: onboarding CTA instead of an empty dropdown. --}}
            <a href="{{ \App\Filament\Resources\PropertyResource::getUrl('create') }}" class="rw-switcher__cta">
                <x-filament::icon icon="heroicon-m-plus-circle" class="rw-switcher__cta-icon" />
                <span>{{ __('Create your first property') }}</span>
            </a>
        @else
            <label class="rw-switcher__label" for="rw-property-select">
                <x-filament::icon icon="heroicon-m-building-office-2" class="rw-switcher__label-icon" />
                {{ __('Current property') }}
            </label>

            <div class="rw-switcher__control">
                <select
                    id="rw-property-select"
                    wire:model.live="propertyId"
                    wire:loading.attr="disabled"
                    class="rw-switcher__select"
                >
                    <option value="">{{ __('All properties') }}</option>
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}">{{ $property->name }}</option>
                    @endforeach
                </select>

                <x-filament::loading-indicator class="rw-switcher__spinner" wire:loading wire:target="propertyId" />
            </div>
        @endif
    @endif
</div>
