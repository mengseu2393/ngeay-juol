{{--
    Simple Mode card for a Property row (PropertyResource::table() swaps its
    Split/Stack columns for this single ViewColumn when reached via
    ?from=simple). Mirrors the room/invoice cards on /app/simple
    (.rw-sm-room-number, .rw-sm-badge, .rw-sm-detail-*), so the list reads as
    part of Simple Mode rather than a bare Filament table.
--}}
@php
    /** @var \App\Models\Property $record */
    $record = $getRecord();
    $type = $record->property_type;
    $units = $record->units_count ?? $record->units()->count();
    $occupied = $record->occupied_units_count ?? null;
    $city = trim(collect([$record->city, $record->province])->filter()->unique()->implode(', '));
@endphp

<div class="rw-sm-prop-card">
    <div class="rw-sm-prop-card-head">
        <div class="min-w-0">
            <p class="rw-sm-room-number truncate">{{ $record->name }}</p>
            @if($city)
                <p class="rw-sm-tenant-name flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true"><path fill-rule="evenodd" d="m9.69 18.933.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003ZM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" clip-rule="evenodd" /></svg>
                    <span class="truncate">{{ $city }}</span>
                </p>
            @endif
        </div>
        @if($type)
            <span class="rw-sm-badge rw-sm-badge-info shrink-0">{{ $type->getLabel() }}</span>
        @endif
    </div>

    <div class="rw-sm-prop-card-stats">
        <div>
            <p class="rw-sm-detail-label">{{ __('Rooms') }}</p>
            <p class="rw-sm-detail-value">{{ trans_choice(':count room|:count rooms', $units, ['count' => $units]) }}</p>
        </div>
        @if($occupied !== null)
            <div>
                <p class="rw-sm-detail-label">{{ __('Occupied') }}</p>
                <p class="rw-sm-detail-value">{{ $occupied }} / {{ $units }}</p>
            </div>
        @endif
        @if(auth()->user()?->isPlatformStaff() && $record->landlord)
            <div>
                <p class="rw-sm-detail-label">{{ __('Landlord') }}</p>
                <p class="rw-sm-detail-value truncate">{{ $record->landlord->name }}</p>
            </div>
        @endif
    </div>
</div>
