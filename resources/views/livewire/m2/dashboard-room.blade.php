@php
    use App\Models\Invoice;
    use App\Support\UtilityCatalog;
    use App\Support\Money;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
@endphp

<div class="m2a">
    <x-m2.header :title="__('Room :room', ['room' => $room->room_number]).' · '.__('History')" :subtitle="($room->activeRental?->occupant_name ?: __('Vacant')).' · '.$property?->name" :back="route('m2.dashboard.property', $room->property_id)" />

    <main class="m2-wrap m2a-main">
        <div class="m2-pills" id="m2-history-kinds">
            <button type="button" wire:click="$set('kind', 'all')" class="m2-pill" aria-pressed="{{ $historyKind === 'all' ? 'true' : 'false' }}">{{ __('All') }}</button>
            @foreach ($meters as $meter)
                <button type="button" wire:click="$set('kind', '{{ $meter->id }}')" class="m2-pill" aria-pressed="{{ $historyKind === (string) $meter->id ? 'true' : 'false' }}" wire:key="m2-kind-{{ $meter->id }}">{{ UtilityCatalog::label($meter->name) }}</button>
            @endforeach
            <button type="button" wire:click="$set('kind', 'bill')" class="m2-pill" aria-pressed="{{ $historyKind === 'bill' ? 'true' : 'false' }}">{{ __('Bills') }}</button>
        </div>

        @if ($history->isEmpty())
            <div class="m2a-empty" id="m2-history-empty">{{ __('Nothing in this period.') }}</div>
        @else
            @foreach ($history as $ym => $month)
                <div class="m2a-mh" wire:key="m2-hist-head-{{ $ym }}">
                    <b>{{ $month['label'] }}</b>
                    @if ($month['billed'] > 0)<span>{{ __('Billed') }} {{ Money::format($month['billed'], $currency) }}@if ($month['paid'] > 0) · {{ __('Paid') }} {{ Money::format($month['paid'], $currency) }}@endif</span>@endif
                </div>
                <div class="m2a-list" wire:key="m2-hist-list-{{ $ym }}" id="m2-history-{{ $ym }}">
                    @foreach ($month['items'] as $item)
                        @if ($item['type'] === 'bill')
                            @php $invoice = $item['invoice']; @endphp
                            <a href="{{ route('m2.slip', $invoice) }}" wire:navigate class="m2a-row" wire:key="m2-hist-bill-{{ $invoice->id }}">
                                <x-m2.icon name="bill" />
                                <span class="m2a-t">
                                    <b class="m2-trunc">{{ __('Bill') }} {{ $invoice->invoice_number }}</b>
                                    <span>{{ Invoice::displayDate($invoice->issue_date) }}</span>
                                </span>
                                <span class="m2a-r">
                                    <b class="m2-num">{{ Money::format($invoice->amount_due, $currency) }}</b>
                                    <span class="m2a-chip is-{{ match ($invoice->payment_status->getColor()) { 'success' => 'ok', 'danger' => 'bad', 'warning' => 'warn', default => 'info' } }}">{{ $invoice->payment_status->getLabel() }}</span>
                                </span>
                                {!! $chev !!}
                            </a>
                        @else
                            @php
                                $usage = $item['usage'];
                                $water = $usage->propertyUtility?->name === 'Water';
                            @endphp
                            <div class="m2a-row is-static" wire:key="m2-hist-use-{{ $usage->id }}">
                                <x-m2.icon :name="$water ? 'water' : 'bolt'" :class="$water ? 'is-w' : 'is-e'" />
                                <span class="m2a-t">
                                    <b class="m2-trunc">{{ $usage->propertyUtility ? UtilityCatalog::label($usage->propertyUtility->name) : '—' }}</b>
                                    <span>{{ $usage->is_reset ? __('Meter reset') : $trim($usage->old_reading).' → '.$trim($usage->new_reading) }} · {{ $usage->reading_date->translatedFormat('d M') }}</span>
                                </span>
                                <span class="m2a-chip is-{{ $water ? 'w' : 'e' }}">{{ $usage->is_reset ? '0' : $trim($usage->amount_used) }} {{ $usage->propertyUtility?->unit_of_measure }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach

            @if ($months < 60)
                <button type="button" wire:click="showEarlier" class="m2-btn is-ghost" id="m2-history-earlier">{{ __('Show earlier months') }}</button>
            @endif
        @endif
    </main>
</div>
