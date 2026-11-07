@php
    use App\Support\Money;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

<div class="m2a">
    <x-m2.header :title="$property->name" :subtitle="__(':rooms rooms · :occupied occupied', ['rooms' => $rooms->count(), 'occupied' => $occupied])" :back="route('m2.dashboard')" />

    <main class="m2-wrap m2a-main">
        <div class="m2a-bar">
            <a href="{{ route('m2.dashboard.income', ['property' => $property->id]) }}" wire:navigate id="m2-open-income">{{ __('Income') }}</a>
            <a href="{{ route('m2.dashboard.occupancy', ['property' => $property->id]) }}" wire:navigate id="m2-open-occupancy">{{ __('Occupancy') }}</a>
        </div>

        @if ($owed > 0)
            <div class="m2a-sum" id="m2-dash-owed-total">
                <div>
                    <small>{{ __('Total owed') }}</small>
                    <b class="m2-num">{{ Money::format($owed, $currency) }}</b>
                </div>
                @if ($late > 0)<span class="m2a-chip is-late">{{ __(':count late', ['count' => $late]) }}</span>@endif
            </div>
        @endif

        @if ($rooms->isEmpty())
            <div class="m2a-empty">{{ __('No rooms found.') }}</div>
        @else
            <div class="m2a-list" id="m2-dash-rooms">
                @foreach ($rooms as $room)
                    @php
                        $owing = $due->get($room->id);
                        $isLate = ($owing['daysLate'] ?? 0) > 0;
                        $state = $owing ? ($isLate ? 'is-late' : 'is-soon') : ($room->activeRental ? 'is-ok' : 'is-idle');
                    @endphp
                    <a href="{{ route('m2.dashboard.room', $room) }}" wire:navigate class="m2a-row" wire:key="m2-dash-room-{{ $room->id }}" id="m2-dash-room-{{ $room->id }}">
                        <span class="m2a-dot {{ $state }}"></span>
                        <span class="m2a-t">
                            <b>{{ $room->room_number }}</b>
                            <span class="m2-trunc">{{ $room->activeRental?->occupant_name ?: __('Vacant') }}</span>
                        </span>
                        <span class="m2a-r">
                            @if ($owing)
                                <b class="m2-num">{{ Money::format($owing['balance'], $currency) }}</b>
                                <span class="{{ $isLate ? 'is-late' : '' }}">{{ $isLate ? __(':days days late', ['days' => $owing['daysLate']]) : __('Due :date', ['date' => $owing['oldest']?->translatedFormat('d M')]) }}</span>
                            @elseif ($room->activeRental)
                                <span class="m2a-chip is-ok">{{ __('Paid up') }}</span>
                            @else
                                <span class="m2a-chip is-idle">{{ __('Vacant') }}</span>
                            @endif
                        </span>
                        {!! $chev !!}
                    </a>
                @endforeach
            </div>
        @endif
    </main>
</div>
