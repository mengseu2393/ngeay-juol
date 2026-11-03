@php
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $letter = fn ($name) => match ($name) { 'Electricity' => 'e', 'Water' => 'w', default => '' };
    $short = fn ($name) => match ($name) { 'Electricity' => 'E', 'Water' => 'W', default => mb_strtoupper(mb_substr(UtilityCatalog::label($name), 0, 1)) };
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    $tone = fn ($i) => $i ? match ($i->payment_status->getColor()) { 'success' => 'ok', 'danger' => 'bad', 'warning' => 'warn', default => 'idle' } : 'idle';
@endphp

<div class="m2a">
    <x-m2.header :title="$unit->room_number" :subtitle="$unit->property?->name" :back="route('m2.home')" />

    <main class="m2-wrap m2a-main m2a-stack">
        {{-- The two things you do from a room: look at its bill, make a new one. --}}
        <div class="m2a-bar">
            <a href="{{ route('m2.bill', $unit) }}" wire:navigate id="m2-room-open-bill"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 14.25v-2.6a3.4 3.4 0 0 0-3.4-3.4h-1.5A1.1 1.1 0 0 1 13.5 7.1v-1.5a3.4 3.4 0 0 0-3.4-3.4H8.25m0 12.8h7.5M8.25 18H12M10.5 2.25H5.6c-.6 0-1.1.5-1.1 1.1v17.3c0 .6.5 1.1 1.1 1.1h12.8c.6 0 1.1-.5 1.1-1.1V11.25a9 9 0 0 0-9-9Z"/></svg>{{ __('Bill') }}</a>
            @if ($rental && $canCreateInvoice)
                <a href="{{ route('m2.invoice.create', $unit) }}" wire:navigate id="m2-room-create-invoice"><svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>{{ __('Create invoice') }}</a>
            @endif
        </div>

        <div class="m2a-sum" id="m2-room-price">
            <div>
                <small>{{ __('Monthly rent') }}</small>
                <b class="m2-num">{{ $price }}</b>
            </div>
            @if ($bill)
                <span class="m2a-chip is-{{ $tone($bill) }}">{{ $bill->payment_status->getLabel() }}</span>
            @else
                <span class="m2a-chip is-idle">{{ __('No bill yet') }}</span>
            @endif
        </div>

        {{-- ── Tenants ── --}}
        <h2 class="m2a-h">{{ __('Tenants') }}</h2>
        @if ($rental)
            <div class="m2a-list" id="m2-room-tenants">
                <a href="{{ route('m2.tenant', $rental) }}" wire:navigate class="m2a-row" id="m2-room-tenant">
                    <span class="m2a-dot is-ok"></span>
                    <span class="m2a-t">
                        <b class="m2-trunc" style="display:block">{{ $rental->occupant_name }}</b>
                        @if ($rental->occupant_phone)<span>{{ $rental->occupant_phone }}</span>@endif
                    </span>
                    {!! $chev !!}
                </a>
                @foreach ($coOccupants as $occupant)
                    <div class="m2a-row is-static" wire:key="m2-occ-{{ $occupant->id }}">
                        <span class="m2a-dot is-idle"></span>
                        <span class="m2a-t">
                            <b class="m2-trunc" style="display:block">{{ $occupant->occupant_name }}</b>
                            <span>{{ $occupant->role === \App\Enums\OccupantRole::Dependent ? __('Dependent') : __('Co-tenant') }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="m2a-empty" id="m2-room-vacant">{{ __('Vacant') }}</div>
        @endif

        {{-- ── Meters: E and W ── --}}
        @if ($meters->isNotEmpty())
            <h2 class="m2a-h">{{ __('Meters') }}</h2>
            <div class="m2a-tiles" id="m2-room-meters">
                @foreach ($meters as $meter)
                    @php $usage = $latest->get($meter->id); @endphp
                    <a href="{{ route('m2.meter', [$unit, $meter]) }}" wire:navigate wire:key="m2-meter-{{ $meter->id }}" class="m2a-tile" id="m2-meter-{{ $meter->id }}">
                        <span class="k">@if ($letter($meter->name))<span class="m2a-lt is-{{ $letter($meter->name) }}">{{ $short($meter->name) }}</span>@endif{{ UtilityCatalog::label($meter->name) }}</span>
                        <span class="v">{{ $usage ? $trim($usage->new_reading) : '–' }}</span>
                        <span class="u">
                            @if ($usage)
                                {{ __('Used') }} {{ $trim($usage->amount_used) }} {{ $meter->unit_of_measure }}
                            @else
                                {{ __('No reading yet') }}
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ── Other services: parking, lift, sanitation… ── --}}
        @if ($services->isNotEmpty())
            <h2 class="m2a-h">{{ __('Services') }}</h2>
            <div class="m2a-list" id="m2-room-services">
                @foreach ($services as $service)
                    <a href="{{ route('m2.service', [$unit, $service]) }}" wire:navigate wire:key="m2-service-{{ $service->id }}" class="m2a-row" id="m2-service-{{ $service->id }}">
                        <span class="m2a-t"><b>{{ UtilityCatalog::label($service->name) }}</b></span>
                        <b class="m2-num">{{ Money::format($service->rate, $service->currency) }}</b>
                        {!! $chev !!}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ── Latest bill ── --}}
        <h2 class="m2a-h">{{ __('Bill') }}</h2>
        <div class="m2a-list">
            <a href="{{ route('m2.bill', $unit) }}" wire:navigate class="m2a-row" id="m2-room-bill">
                <span class="m2a-dot is-{{ $tone($bill) }}"></span>
                <span class="m2a-t">
                    @if ($bill)
                        <b class="m2-trunc" style="display:block">{{ $bill->billingPeriodLabel('d M') }}</b>
                        <span>{{ $bill->payment_status->getLabel() }}</span>
                    @else
                        <b>{{ __('No bill yet') }}</b>
                    @endif
                </span>
                @if ($bill)<b class="m2-num">{{ Money::formatForRecord($bill->amount_due, $bill) }}</b>@endif
                {!! $chev !!}
            </a>
        </div>
    </main>
</div>
