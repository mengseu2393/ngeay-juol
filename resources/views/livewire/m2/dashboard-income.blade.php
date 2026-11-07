@php
    use App\Support\Money;
@endphp

<div class="m2a">
    <x-m2.header :title="__('Income')" :subtitle="($property?->name ?? __('All properties')).' · '.__('Last :count months', ['count' => $months->count()])" :back="$property ? route('m2.dashboard.property', $property) : route('m2.dashboard')" />

    <main class="m2-wrap m2a-main">
        <div class="m2a-sum is-col" id="m2-income-summary">
            <div class="m2a-sumrow">
                <div>
                    <small>{{ __('Received') }} · {{ __('Last :count months', ['count' => $months->count()]) }}</small>
                    <b class="m2-num" id="m2-income-total">{{ Money::format($sum, $currency) }}</b>
                </div>
                @if ($trend !== null)
                    <span class="m2a-chip {{ $trend >= 0 ? 'is-ok' : 'is-late' }}" id="m2-income-trend">{{ ($trend >= 0 ? '+' : '−').abs($trend) }}%</span>
                @endif
            </div>
            <div class="m2a-bars" role="img" aria-label="{{ __('Income by month') }}" id="m2-income-bars">
                @foreach ($months->reverse() as $ym => $month)
                    <div class="m2a-bc {{ $loop->last ? 'is-now' : '' }}">
                        <i style="height: {{ max(3, (int) round($month['total'] / $peak * 100)) }}%"></i>
                        <span>{{ $month['short'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="m2a-h">{{ __('By month') }}</div>
        <div class="m2a-list" id="m2-income-months">
            @foreach ($months as $ym => $month)
                <div class="m2a-row is-static" wire:key="m2-income-{{ $ym }}">
                    <span class="m2a-dot {{ $loop->first ? 'is-ok' : 'is-idle' }}"></span>
                    <span class="m2a-t">
                        <b class="m2-trunc">{{ $month['label'] }}</b>
                        <span>{{ __(':count payment(s)', ['count' => $month['count']]) }}</span>
                    </span>
                    <span class="m2a-r"><b class="m2-num">{{ Money::format($month['total'], $currency) }}</b></span>
                </div>
            @endforeach
        </div>
    </main>
</div>
