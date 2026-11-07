@php
    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

<div class="m2a">
    <x-m2.header :title="__('Occupancy')" :subtitle="($property?->name ?? __('All properties')).' · '.__(':count rooms', ['count' => $total])" :back="$property ? route('m2.dashboard.property', $property) : route('m2.dashboard')" />

    <main class="m2-wrap m2a-main">
        <div class="m2a-card" id="m2-occupancy-summary">
            <div class="m2-row">
                <span class="m2-grow m2-muted">{{ __('Today') }}</span>
                <b style="font-size:26px" class="m2-num" id="m2-occupancy-percent">{{ $percent }}%</b>
            </div>
            @if ($total > 0)
                <div class="m2-split" style="margin-top:10px" aria-hidden="true">
                    <i class="is-occ" style="width: {{ $occupied / $total * 100 }}%"></i>
                    <i class="is-vac" style="width: {{ $available / $total * 100 }}%"></i>
                    <i class="is-oth" style="width: {{ $other / $total * 100 }}%"></i>
                </div>
            @endif
            <div class="m2-legend">
                <span><i style="background:var(--m2-brand)"></i>{{ __('Occupied') }} {{ $occupied }}</span>
                <span><i style="background:var(--m2-info)"></i>{{ __('Vacant') }} {{ $available }}</span>
                @if ($other > 0)<span><i style="background:var(--m2-warn)"></i>{{ __('Maintenance') }} {{ $other }}</span>@endif
            </div>
        </div>

        @if ($total > 0)
            <div class="m2a-h">{{ __('By month') }}</div>
            <div class="m2a-list" id="m2-occupancy-months">
                @foreach ($months as $month)
                    <div class="m2a-row is-static" wire:key="m2-occ-month-{{ $loop->index }}">
                        <span class="m2a-t">
                            <b class="m2-trunc">{{ $month['label'] }}</b>
                            <span>{{ __(':occupied of :total rooms', ['occupied' => $month['occupied'], 'total' => $month['total']]) }}</span>
                        </span>
                        <span class="m2a-r"><b class="m2-num">{{ $month['percent'] }}%</b></span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($attention->isNotEmpty())
            <div class="m2a-h">{{ __('Rooms needing attention') }}</div>
            <div class="m2a-list" id="m2-occupancy-attention">
                @foreach ($attention as $room)
                    <a href="{{ route('m2.room', $room) }}" wire:navigate class="m2a-row" wire:key="m2-occ-room-{{ $room->id }}">
                        <span class="m2a-dot is-idle"></span>
                        <span class="m2a-t">
                            <b class="m2-trunc">{{ $room->room_number }}@if (! $property) · {{ $room->property?->name }}@endif</b>
                            <span>{{ $room->status->getLabel() }}</span>
                        </span>
                        {!! $chev !!}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($total === 0)
            <div class="m2a-empty">{{ __('No rooms found.') }}</div>
        @endif
    </main>
</div>
