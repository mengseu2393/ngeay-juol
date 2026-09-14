{{--
    Simple Mode card for a PropertyUtility row (PropertyUtilityResource::table()
    swaps its Split/Stack columns for this single ViewColumn when reached via
    ?from=simple). Same look as the room/invoice cards on /app/simple.
--}}
@php
    /** @var \App\Models\PropertyUtility $record */
    use App\Filament\Resources\PropertyUtilityResource;
    use App\Support\Money;

    $record = $getRecord();
    $type = $record->billing_type;
@endphp

<div class="rw-sm-prop-card">
    <div class="rw-sm-prop-card-head">
        <div class="min-w-0">
            <p class="rw-sm-room-number truncate">{{ PropertyUtilityResource::utilityLabel((string) $record->name) }}</p>
            @if($record->provider)
                <p class="rw-sm-tenant-name truncate">{{ $record->provider }}</p>
            @endif
        </div>
        <span class="rw-sm-badge {{ $record->is_active ? 'rw-sm-badge-success' : 'rw-sm-badge-gray' }} shrink-0">
            {{ $record->is_active ? __('Active') : __('Inactive') }}
        </span>
    </div>

    <div class="rw-sm-prop-card-stats">
        <div>
            <p class="rw-sm-detail-label">{{ __('Rate') }}</p>
            <p class="rw-sm-detail-value">
                {{ Money::formatForRecord($record->rate, $record) }}@if($record->unit_of_measure)<span class="text-gray-400 font-normal"> / {{ $record->unit_of_measure }}</span>@endif
            </p>
        </div>
        @if($type)
            <div>
                <p class="rw-sm-detail-label">{{ __('Billing type') }}</p>
                <p class="rw-sm-detail-value">{{ $type->getLabel() }}</p>
            </div>
        @endif
        @if($record->unit_of_measure)
            <div>
                <p class="rw-sm-detail-label">{{ __('Unit of measure') }}</p>
                <p class="rw-sm-detail-value">{{ $record->unit_of_measure }}</p>
            </div>
        @endif
    </div>
</div>
