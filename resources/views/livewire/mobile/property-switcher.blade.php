<div class="min-w-0 flex-1">
    @if (! $this->isEligible())
        <p class="truncate text-sm font-semibold">{{ __('No property assigned — contact your administrator.') }}</p>
    @else
        @php($properties = $this->properties())

        @if ($properties->isEmpty())
            @if ($this->canAddProperty())
                <a href="{{ \App\Filament\Pages\AddProperty::getUrl() }}" class="inline-flex items-center gap-1.5 font-semibold" id="rw-m-first-property">
                    <span aria-hidden="true">＋</span> {{ __('Create your first property') }}
                </a>
            @else
                <p class="truncate text-sm font-semibold">{{ __('No property assigned — contact your administrator.') }}</p>
            @endif
        @else
            {{-- A native <select>: the phone's own picker (wheel on iOS, sheet on Android). --}}
            <label for="rw-m-property-select" class="sr-only">{{ __('Current property') }}</label>
            <div class="relative inline-flex max-w-full items-center">
                <select
                    id="rw-m-property-select"
                    wire:model.live="propertyId"
                    wire:loading.attr="disabled"
                    class="max-w-full appearance-none truncate rounded-lg bg-transparent py-1.5 pr-7 pl-0 text-lg font-bold text-white focus:outline-none [&>option]:text-slate-900"
                >
                    @if ($propertyId === '')
                        <option value="" disabled>{{ __('Select a property') }}</option>
                    @endif
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}">{{ $property->name }}</option>
                    @endforeach
                    @if ($this->canAddProperty())
                        <option value="{{ \App\Livewire\PropertySwitcher::ADD }}">＋ {{ __('Add property') }}</option>
                    @endif
                </select>
                <svg class="pointer-events-none absolute right-0 size-5 text-white/80" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </div>
        @endif
    @endif
</div>
