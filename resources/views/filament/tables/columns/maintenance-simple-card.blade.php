{{--
    Simple Mode card for a MaintenanceRequest row (MaintenanceRequestResource::table()
    swaps its columns for this single ViewColumn when reached via ?from=simple).
    Mirrors the invoice cards on /app/simple?screen=invoices: room + tenant up
    top, status badge on the right, then a 2-column detail grid.
--}}
@php
    /** @var \App\Models\MaintenanceRequest $record */
    $record = $getRecord();
    $badge = fn (?string $color) => match ($color) {
        'success' => 'rw-sm-badge-success',
        'warning' => 'rw-sm-badge-warning',
        'danger'  => 'rw-sm-badge-danger',
        'info'    => 'rw-sm-badge-info',
        default   => 'rw-sm-badge-gray',
    };
@endphp

<div class="rw-sm-prop-card">
    <div class="rw-sm-prop-card-head">
        <div class="min-w-0">
            <p class="rw-sm-room-number truncate">{{ $record->unit?->room_number ?? ($record->property?->name ?? '—') }}</p>
            <p class="rw-sm-tenant-name truncate">{{ $record->tenant?->name ?? $record->rental?->occupant_name ?? '—' }}</p>
        </div>
        @if($record->status)
            <span class="rw-sm-badge {{ $badge($record->status->getColor()) }} shrink-0">{{ $record->status->getLabel() }}</span>
        @endif
    </div>

    <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $record->title }}</p>
    @if($record->description)
        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $record->description }}</p>
    @endif

    <div class="mt-3 grid grid-cols-2 gap-y-1.5 text-sm">
        <div>
            <p class="rw-sm-detail-label">{{ __('Priority') }}</p>
            <p class="rw-sm-detail-value">
                @if($record->priority)
                    <span class="rw-sm-badge {{ $badge($record->priority->getColor()) }}">{{ $record->priority->getLabel() }}</span>
                @else
                    —
                @endif
            </p>
        </div>
        <div>
            <p class="rw-sm-detail-label">{{ __('Created at') }}</p>
            <p class="rw-sm-detail-value">{{ $record->created_at?->format('d M Y') ?? '—' }}</p>
        </div>
    </div>
</div>
