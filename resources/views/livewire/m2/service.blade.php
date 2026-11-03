@php
    use App\Support\Money;
    use App\Support\UtilityCatalog;
@endphp

<div class="m2a">
    <x-m2.header :title="__('Room').' '.$unit->room_number" :subtitle="$unit->activeRental?->occupant_name ?? __('Vacant')" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main">
        <div class="m2a-sum" id="m2-service-price">
            <div>
                <small>{{ __('Price') }}</small>
                <b class="m2-num">{{ Money::format($utility->rate, $utility->currency) }}</b>
            </div>
            <span class="m2a-chip is-idle">{{ $utility->unit_of_measure ? __('per :unit', ['unit' => $utility->unit_of_measure]) : __('per month') }}</span>
        </div>

        <div class="m2a-list" id="m2-service-info">
            <div class="m2a-kv"><span>{{ __('Service') }}</span><b>{{ UtilityCatalog::label($utility->name) }}</b></div>
            @if ($utility->billing_type)<div class="m2a-kv"><span>{{ __('Billing') }}</span><b>{{ $utility->billing_type->getLabel() }}</b></div>@endif
            @if ($addedTo)<div class="m2a-kv" id="m2-service-added"><span>{{ __('Added to') }}</span><b>{{ $addedTo }}</b></div>@endif
            @if ($utility->provider)<div class="m2a-kv"><span>{{ __('Provider') }}</span><b>{{ $utility->provider }}</b></div>@endif
            @if ($utility->notes)<div class="m2a-kv"><span>{{ __('Notes') }}</span><b>{{ $utility->notes }}</b></div>@endif
        </div>

        @if ($services->count() > 1)
            <h2 class="m2a-h">{{ __('Services') }}</h2>
            <div class="m2-pills" id="m2-service-others">
                @foreach ($services as $service)
                    <a href="{{ route('m2.service', [$unit, $service]) }}" wire:navigate wire:key="m2-svc-{{ $service->id }}" class="m2-pill" aria-pressed="{{ $service->is($utility) ? 'true' : 'false' }}">{{ UtilityCatalog::label($service->name) }}</a>
                @endforeach
            </div>
        @endif
    </main>
</div>
