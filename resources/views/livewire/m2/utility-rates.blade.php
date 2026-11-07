@php
    use App\Enums\BillingType;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    $filters = ['all' => __('All'), 'active' => __('Active'), 'inactive' => __('Inactive')];
    $billingShort = [
        BillingType::Metered->value => __('Metered'),
        BillingType::Flat->value => __('Flat'),
        BillingType::Shared->value => __('Shared'),
    ];
@endphp

<div id="m2-utility-rates" class="m2a">
    <x-m2.header :title="__('Utility Rates')" :subtitle="$property?->name" :back="route('m2.settings')" />

    <main class="m2-wrap m2a-main">
        @if ($properties->count() > 1)
            <div>
                <label for="m2-utility-property" class="m2-label">{{ __('Property') }}</label>
                <select id="m2-utility-property" class="m2-input" wire:change="switchProperty($event.target.value)">
                    @foreach ($properties as $item)
                        <option value="{{ $item->id }}" @selected($item->id === $property?->id)>{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (! $property)
            <div class="m2a-empty">{{ __('Choose a property first') }}</div>
        @else
            <div class="m2a-bar" style="margin-top:4px">
                <a href="{{ route('m2.utilities.new') }}" wire:navigate id="m2-utility-add">
                    <svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>{{ __('Add utility') }}
                </a>
                <a href="{{ route('m2.utilities.history') }}" wire:navigate id="m2-utility-history">
                    <svg class="m2a-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>{{ __('Reading history') }}
                </a>
            </div>

            <div class="m2-pills" role="group" aria-label="{{ __('Filter') }}">
                @foreach ($filters as $value => $label)
                    <button type="button" wire:click="$set('filter', '{{ $value }}')" class="m2-pill" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" id="m2-utility-filter-{{ $value }}">{{ $label }}</button>
                @endforeach
            </div>

            @if ($utilities->isEmpty())
                <div class="m2a-empty" id="m2-utility-empty">{{ $filter === 'all' ? __('No utilities yet.') : __('No utilities found.') }}</div>
            @else
                <div class="m2a-list" id="m2-utility-list">
                    @foreach ($utilities as $utility)
                        <a href="{{ route('m2.utilities.edit', $utility) }}" wire:navigate class="m2a-row" id="m2-utility-{{ $utility->id }}" wire:key="m2-utility-{{ $utility->id }}">
                            @if ($utility->name === 'Electricity')<span class="m2a-lt is-e">E</span>
                            @elseif ($utility->name === 'Water')<span class="m2a-lt is-w">W</span>
                            @else<span class="m2a-dot {{ $utility->is_active ? 'is-ok' : 'is-idle' }}"></span>@endif
                            <span class="m2a-t">
                                <b class="m2-trunc">{{ UtilityCatalog::label($utility->name) }}</b>
                                <span class="m2-trunc">{{ collect([$billingShort[$utility->billing_type?->value] ?? null, $utility->provider])->filter()->implode(' · ') }}</span>
                            </span>
                            @if ($utility->is_active)
                                <span class="m2a-r">
                                    <b>{{ Money::formatForRecord($utility->rate, $utility) }}</b>
                                    @if ($utility->unit_of_measure)<span>{{ __('per :unit', ['unit' => $utility->unit_of_measure]) }}</span>@endif
                                </span>
                            @else
                                <span class="m2a-chip is-idle">{{ __('Inactive') }}</span>
                            @endif
                            {!! $chev !!}
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    </main>
</div>
