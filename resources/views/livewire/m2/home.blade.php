@php
    use App\Support\Money;
@endphp

{{-- Home, style A "Quiet list" (approved from the design options): name bar, two buttons, total owed, one list. --}}
<div class="m2a">
    @php
        $me = auth()->user();
        $photo = \App\Support\M2Shell::mediaUrl($me?->getFirstMediaUrl('avatar'));
        $heroTitle = __('Hi, :name', ['name' => $me->name]);
        $heroSub = $scoped ? $property->name : __('All properties');
        $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    @endphp

    <header class="m2a-top m2-wrap">
        <a href="{{ route('m2.settings') }}" wire:navigate class="m2a-avatar" aria-label="{{ __('Settings') }}" id="m2-open-settings">
            @if ($photo)<img src="{{ $photo }}" alt="">@else{{ mb_strtoupper(mb_substr($me->name ?? '?', 0, 1)) }}@endif
        </a>
        <div class="m2a-name">
            <b>{{ $heroTitle }}</b>
            <small class="m2a-sub">{{ $heroSub }}</small>
        </div>
    </header>

    <main class="m2-wrap m2a-main">
        <div class="m2a-bar">
            @if ($view === 'rooms')
                <button type="button" wire:click="setView('properties')" id="m2-view-properties"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.25 21h19.5M4.5 3v18M13.5 3v18M19.5 7.5V21M7.5 7.5h3m-3 4h3m-3 4h3"/></svg>{{ __('Properties') }}</button>
            @else
                <button type="button" wire:click="setView('rooms')" id="m2-view-rooms"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.25 21V4.5A1.5 1.5 0 0 1 9.75 3h6a1.5 1.5 0 0 1 1.5 1.5V21M4.5 21h15"/></svg>{{ __('Rooms') }}</button>
            @endif
            <a href="{{ route('m2.dashboard') }}" wire:navigate id="m2-open-dashboard"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10m6 10V4m6 16v-7"/></svg>{{ __('Dashboard') }}</a>
        </div>

        @if ($view === 'rooms' && $summary['total'] > 0)
            <div class="m2a-sum" id="m2a-sum">
                <div>
                    <small>{{ __('Total owed') }}</small>
                    <b class="m2-num">{{ Money::format($summary['total'], 'USD') }}</b>
                </div>
                @if ($summary['late'] > 0)<span class="m2a-chip is-late">{{ __(':count late', ['count' => $summary['late']]) }}</span>@endif
            </div>
        @endif

        @if ($view === 'properties')
            @if ($properties->isEmpty())
                <div class="m2a-empty">{{ __('No properties yet.') }}</div>
            @else
                <div class="m2a-list" id="m2-properties">
                    @foreach ($properties as $item)
                        @php $isOpen = $item->id === $propertyId; @endphp
                        <button type="button" wire:key="m2-prop-{{ $item->id }}" wire:click="selectProperty({{ $item->id }})" class="m2a-row" id="m2-property-{{ $item->id }}">
                            <span class="m2a-dot {{ $isOpen ? 'is-ok' : 'is-idle' }}"></span>
                            <span class="m2a-t">
                                <b class="m2-trunc" style="display:block">{{ $item->name }}</b>
                                <span>{{ __(':rooms rooms · :occupied occupied', ['rooms' => $item->units_count, 'occupied' => $item->occupied_count]) }}</span>
                            </span>
                            {!! $chev !!}
                        </button>
                    @endforeach
                </div>
            @endif
        @else
            @if ($scoped)
                <div class="m2a-scope" id="m2-scope">
                    <span class="m2a-chip is-ok">{{ $property->name }}</span>
                    <button type="button" wire:click="showAllRooms" id="m2-show-all">{{ __('Show all rooms') }}</button>
                </div>
            @endif

            @if ($rooms->isEmpty())
                <div class="m2a-empty">{{ __('No rooms found.') }}</div>
            @else
                <div class="m2a-list" id="m2-rooms">
                    @foreach ($rooms as $room)
                        @php
                            $owed = $due->get($room->id);
                            $late = ($owed['daysLate'] ?? 0) > 0;
                            $tenant = $room->activeRental?->occupant_name;
                            $roomCurrency = Money::forPropertyId($room->property_id);
                            $state = $owed ? ($late ? 'is-late' : 'is-soon') : ($room->activeRental ? 'is-ok' : 'is-idle');
                        @endphp
                        <a href="{{ route('m2.room', $room) }}" wire:navigate wire:key="m2-room-{{ $room->id }}" class="m2a-row" id="m2-room-{{ $room->id }}">
                            <span class="m2a-dot {{ $state }}"></span>
                            <span class="m2a-t">
                                <b>{{ $room->room_number }}</b>
                                <span class="m2-trunc">{{ $tenant ?: __('Vacant') }}@if ($showPropertyName) · {{ $propertyNames[$room->property_id] ?? '' }}@endif</span>
                            </span>
                            <span class="m2a-r">
                                @if ($owed)
                                    <b class="m2-num">{{ Money::format($owed['balance'], $roomCurrency) }}</b>
                                    <span class="{{ $late ? 'is-late' : '' }}">{{ $late ? __(':days days late', ['days' => $owed['daysLate']]) : __('Due :date', ['date' => $owed['oldest']?->translatedFormat('d M')]) }}</span>
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

            @if ($canAddRoom)
                {{-- Opened on a property, the room goes there; otherwise the form asks which one. --}}
                <a href="{{ route('m2.add-room', $scoped ? ['property' => $propertyId] : []) }}" wire:navigate class="m2-btn is-ghost" style="margin-top:12px" id="m2-add-room">{{ __('Add room') }}</a>
            @endif
        @endif
    </main>
</div>
