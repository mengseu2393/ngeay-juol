<div class="space-y-4">

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Room or tenant name…') }}"
            class="rw-sm-search-input"
            id="room-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    {{-- ── Room list ── --}}
    @forelse($rooms as $room)
        @php
            $statusLabel = $room->status->getLabel();
            $statusColor = match($room->status->getColor()) {
                'success' => 'rw-sm-badge-success',
                'info'    => 'rw-sm-badge-info',
                'warning' => 'rw-sm-badge-warning',
                default   => 'rw-sm-badge-gray',
            };
            $tenantName = $room->activeRental?->occupant_name ?: ($room->activeRental?->tenant?->name ?? null);
        @endphp

        <div class="rw-sm-invoice-card" id="room-card-{{ $room->id }}">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="rw-sm-room-number">{{ $room->room_number }}</p>
                    @if($tenantName)
                        <p class="rw-sm-tenant-name">{{ $tenantName }}</p>
                    @endif
                    @if($room->activeRental?->monthly_rent)
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ \App\Support\Money::format($room->activeRental->monthly_rent, $room->property?->currency) }}/{{ __('mo') }}
                        </p>
                    @endif
                </div>
                <span class="rw-sm-badge {{ $statusColor }} shrink-0">{{ $statusLabel }}</span>
            </div>

            {{-- Quick actions --}}
            <div class="mt-3 flex flex-wrap gap-2">
                {{-- View invoices --}}
                <a href="{{ \App\Filament\Resources\InvoiceResource::getUrl('index', ['tableFilters[unit_id][value]' => $room->id], panel: 'landlord') }}"
                   class="rw-sm-btn-ghost text-sm"
                   id="room-invoices-{{ $room->id }}"
                >{{ __('Invoices') }}</a>

                @if($room->status === \App\Enums\UnitStatus::Available)
                    {{-- Add tenant — deep-links straight past the room-picker step --}}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'add-tenant', 'unit_id' => $room->id]) }}"
                       class="rw-sm-btn-primary text-sm"
                       id="room-add-tenant-{{ $room->id }}"
                    >{{ __('Add tenant') }}</a>
                @elseif($room->status === \App\Enums\UnitStatus::Occupied)
                    {{-- End tenancy — deep-links straight past the room-picker step --}}
                    <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'end-tenancy', 'unit_id' => $room->id]) }}"
                       class="rw-sm-btn-warning text-sm"
                       id="room-end-tenancy-{{ $room->id }}"
                    >{{ __('End tenancy') }}</a>
                @endif
            </div>
        </div>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No rooms found.') }}</p>
        </div>
    @endforelse
</div>
