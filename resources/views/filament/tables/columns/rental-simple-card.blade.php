{{--
    Simple Mode card for a Rental row (RentalResource::table() swaps its
    Split/Stack columns for this single ViewColumn when reached via
    ?from=simple). Tenant-first, mirroring the Tenants tab on /app/simple:
    name as the heading, room as a chip, then the figures a landlord checks
    on a phone (rent, balance due, move-in, phone).
--}}
@php
    /** @var \App\Models\Rental $record */
    $record = $getRecord();
    $name = $record->occupant_name ?: ($record->tenant?->name ?? __('Tenant'));
    $status = $record->status;
    $statusClass = match ($status?->getColor()) {
        'success' => 'rw-sm-badge-success',
        'warning' => 'rw-sm-badge-warning',
        'danger'  => 'rw-sm-badge-danger',
        default   => 'rw-sm-badge-gray',
    };
    $due = \App\Livewire\SimpleTenantList::totalDueFor($record);
    $hasDue = $record->invoices->sum(fn ($i) => $i->balance_usd + $i->balance_khr) > 0;
    $occupants = $record->occupants_count ?? 0;
@endphp

<div class="rw-sm-prop-card">
    <div class="rw-sm-prop-card-head">
        <div class="min-w-0">
            <p class="rw-sm-room-number truncate">{{ $name }}</p>
            <p class="rw-sm-tenant-name flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="rw-sm-badge rw-sm-badge-gray">{{ __('Room') }} {{ $record->unit?->room_number ?? '—' }}</span>
                @if($record->occupant_phone)
                    <span class="truncate">{{ $record->occupant_phone }}</span>
                @endif
            </p>
        </div>
        @if($status)
            <span class="rw-sm-badge {{ $statusClass }} shrink-0">{{ $status->getLabel() }}</span>
        @endif
    </div>

    <div class="rw-sm-prop-card-stats">
        <div>
            <p class="rw-sm-detail-label">{{ __('Monthly rent') }}</p>
            <p class="rw-sm-detail-value">{{ \App\Support\Money::formatForRecord($record->monthly_rent, $record) }}</p>
        </div>
        <div>
            <p class="rw-sm-detail-label">{{ __('Total due') }}</p>
            <p class="rw-sm-detail-value {{ $hasDue ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $due }}</p>
        </div>
        @if($record->start_date)
            <div>
                <p class="rw-sm-detail-label">{{ __('Move-in') }}</p>
                <p class="rw-sm-detail-value">{{ $record->start_date->format('d M Y') }}</p>
            </div>
        @endif
        @if($record->move_out_date)
            <div>
                <p class="rw-sm-detail-label">{{ __('Moved out') }}</p>
                <p class="rw-sm-detail-value">{{ $record->move_out_date->format('d M Y') }}</p>
            </div>
        @endif
        @if($occupants > 1)
            <div>
                <p class="rw-sm-detail-label">{{ __('Occupants') }}</p>
                <p class="rw-sm-detail-value">{{ $occupants }}</p>
            </div>
        @endif
        @if($record->tenant?->username)
            <div>
                <p class="rw-sm-detail-label">{{ __('Login') }}</p>
                <p class="rw-sm-detail-value font-mono truncate">{{ $record->tenant->username }}</p>
            </div>
        @endif
    </div>
</div>
