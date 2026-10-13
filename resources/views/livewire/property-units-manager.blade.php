<div class="rw-um" id="property-units-manager">
    {{-- ── Summary / filter chips ── --}}
    <div class="rw-um-chips" role="tablist">
        @foreach (['all' => [__('All'), $total], 'vacant' => [__('Vacant'), $vacant], 'occupied' => [__('Occupied'), $occupied]] as $key => [$label, $n])
            <button type="button" role="tab" wire:click="$set('filter', '{{ $key }}')"
                class="rw-um-chip {{ $filter === $key ? 'is-active' : '' }}" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                id="um-filter-{{ $key }}">
                {{ $label }} <b>{{ $n }}</b>
            </button>
        @endforeach
    </div>

    <input type="search" wire:model.live.debounce.300ms="search" class="rw-um-search" id="um-search"
        placeholder="{{ __('Room number…') }}" aria-label="{{ __('Room number…') }}" inputmode="search">

    {{-- ── Unit cards ── --}}
    @forelse ($units as $unit)
        @php
            $rental = $unit->activeRental;
            $tenantName = $rental?->occupant_name ?: $rental?->tenant?->name;
            $status = $unit->status;
        @endphp
        <article class="rw-um-card" wire:key="unit-{{ $unit->id }}" id="um-unit-{{ $unit->id }}">
            <header class="rw-um-card-head">
                <div class="rw-um-room">
                    <strong>{{ __('Room :room', ['room' => $unit->room_number]) }}</strong>
                    @if ($unit->floor_number !== null && $unit->floor_number !== '')
                        <span class="rw-um-muted">{{ __('Floor :n', ['n' => $unit->floor_number]) }}</span>
                    @endif
                </div>
                <span class="rw-um-badge rw-um-badge-{{ $status === \App\Enums\UnitStatus::Occupied ? 'occupied' : ($status === \App\Enums\UnitStatus::Available ? 'vacant' : 'other') }}">{{ $status->getLabel() }}</span>
            </header>

            <dl class="rw-um-info">
                <div><dt>{{ __('Rent') }}</dt><dd>{{ \App\Support\Money::formatForRecord($unit->rent_amount, $unit) }}</dd></div>
                <div>
                    <dt>{{ __('Agreement') }}</dt>
                    <dd>
                        @if ($rental)
                            {{ $tenantName ?: '—' }}
                            <span class="rw-um-muted">· {{ __('since :date', ['date' => $rental->start_date?->format('d M Y')]) }}</span>
                        @else
                            <span class="rw-um-muted">{{ __('No active agreement') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <a class="rw-um-btn rw-um-btn-primary" href="{{ $simple ? route('filament.landlord.pages.simple', ['screen' => 'unit', 'unit_id' => $unit->id]) : \App\Filament\Pages\UnitOverview::getUrl(['unit' => $unit->id]) }}" id="um-open-{{ $unit->id }}">{{ __('Open room') }}</a>
        </article>
    @empty
        <p class="rw-um-empty">{{ __('No rooms found.') }}</p>
    @endforelse
</div>
