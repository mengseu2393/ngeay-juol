@php
    use App\Support\Money;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

{{-- Dashboard in Home's "Quiet list" style: button bar, a summary card, then the properties. --}}
<div class="m2a">
    <x-m2.header :title="__('Dashboard')" :subtitle="__('All properties').' · '.now()->translatedFormat('F Y')" :back="route('m2.home')" />

    <main class="m2-wrap m2a-main">
        <div class="m2a-bar">
            <a href="{{ route('m2.dashboard.income') }}" wire:navigate id="m2-open-income"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10m6 10V4m6 16v-7"/></svg>{{ __('Income') }}</a>
            <a href="{{ route('m2.dashboard.occupancy') }}" wire:navigate id="m2-open-occupancy"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.25 21V4.5A1.5 1.5 0 0 1 9.75 3h6a1.5 1.5 0 0 1 1.5 1.5V21M4.5 21h15"/></svg>{{ __('Occupancy') }}</a>
        </div>

        <div class="m2a-sum is-col" id="m2-dash-summary">
            <div class="m2a-sumrow">
                <div>
                    <small>{{ __('Income this month') }}</small>
                    <b class="m2-num" id="m2-dash-income">{{ Money::format($income, 'USD') }}</b>
                </div>
                @if ($trend !== null)
                    <span class="m2a-chip {{ $trend >= 0 ? 'is-ok' : 'is-late' }}" id="m2-dash-trend">{{ ($trend >= 0 ? '+' : '−').abs($trend) }}%</span>
                @endif
            </div>
            <div class="m2a-stats">
                <div><small>{{ __('Owed') }}</small><b class="m2-num {{ $owed > 0 ? 'is-bad' : '' }}" id="m2-dash-owed">{{ Money::format($owed, 'USD') }}</b></div>
                <div><small>{{ __('Occupancy') }}</small><b class="m2-num" id="m2-dash-occupancy">{{ $occupancy }}%</b></div>
                <div><small>{{ __('Bills') }}</small><b class="m2-num" id="m2-dash-bills">{{ $bills }}</b></div>
            </div>
        </div>

        <div class="m2a-h">{{ __('Properties') }}</div>
        @if ($rows->isEmpty())
            <div class="m2a-empty">{{ __('No properties yet.') }}</div>
        @else
            <div class="m2a-list" id="m2-dash-properties">
                @foreach ($rows as $row)
                    @php $property = $row['property']; @endphp
                    <a href="{{ route('m2.dashboard.property', $property) }}" wire:navigate class="m2a-row" wire:key="m2-dash-prop-{{ $property->id }}" id="m2-dash-property-{{ $property->id }}">
                        <span class="m2a-dot {{ $row['late'] > 0 ? 'is-late' : ($row['owing'] > 0 ? 'is-soon' : 'is-ok') }}"></span>
                        <span class="m2a-t">
                            <b class="m2-trunc">{{ $property->name }}</b>
                            <span>{{ __(':rooms rooms · :occupied occupied', ['rooms' => $property->units_count, 'occupied' => $property->occupied_count]) }}</span>
                        </span>
                        <span class="m2a-r">
                            <b class="m2-num">{{ Money::format($row['income'], $row['currency']) }}</b>
                            <span class="{{ $row['late'] > 0 ? 'is-late' : '' }}">{{ $row['late'] > 0 ? __(':count late', ['count' => $row['late']]) : ($row['owing'] > 0 ? __(':count due', ['count' => $row['owing']]) : __('Paid up')) }}</span>
                        </span>
                        {!! $chev !!}
                    </a>
                @endforeach
            </div>
        @endif
    </main>
</div>
