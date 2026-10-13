<div>
    @if ($empty)
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('Pick a room from Rooms to see its details.') }}</p>
            <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'rooms']) }}" class="rw-sm-btn-primary mt-4 inline-block" id="unit-pick-room">{{ __('Rooms') }}</a>
        </div>
    @else
        @include('partials.unit-overview-body')
    @endif
</div>
