@php
    use App\Models\Invoice;
    use App\Support\Money;

    $tone = fn (?Invoice $i) => $i ? match ($i->payment_status->getColor()) { 'success' => 'ok', 'danger' => 'bad', 'warning' => 'warn', default => 'idle' } : 'info';
@endphp

<div class="m2a">
    <x-m2.header :title="__('Room').' '.$unit->room_number" :subtitle="__('Bill')" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main">
        @if (! $invoice)
            <div class="m2a-empty" id="m2-bill-empty">{{ __('No bill yet') }}</div>
        @else
            @if ($recent->count() > 1)
                <div class="m2-pills" id="m2-bill-pills">
                    @foreach ($recent as $item)
                        <button type="button" wire:click="pick({{ $item->id }})" wire:key="m2-bill-pill-{{ $item->id }}" class="m2-pill" aria-pressed="{{ $item->is($invoice) ? 'true' : 'false' }}">{{ $item->billingPeriodLabel('M Y', '–') }}</button>
                    @endforeach
                </div>
            @endif

            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:0 4px">
                <span class="m2-muted m2-sm">{{ $invoice->invoice_number }} · {{ $invoice->billingPeriodLabel('d M') }}</span>
                <span class="m2a-chip is-{{ $tone($invoice) }}">{{ $invoice->payment_status->getLabel() }}</span>
            </div>

            <div class="m2a-list" id="m2-bill-lines">
                @forelse ($lines as $line)
                    <div class="m2a-kv" wire:key="m2-line-{{ $line->id }}">
                        <span>{{ $line->getTranslatedDescription() }}</span>
                        <b class="m2-num">{{ Money::format($line->amount, $line->currency ?: Money::forRecord($invoice)) }}</b>
                    </div>
                @empty
                    <div class="m2a-kv"><span>{{ __('No charges') }}</span><b>—</b></div>
                @endforelse
                <div class="m2a-kv is-total">
                    <span>{{ __('Total') }}</span>
                    <b class="m2-num" id="m2-bill-total">{{ Money::formatForRecord($invoice->amount_due, $invoice) }}</b>
                </div>
            </div>

            @if ((float) $invoice->amount_paid > 0)
                <div class="m2a-list">
                    <div class="m2a-kv"><span>{{ __('Paid') }}</span><b class="m2-num">{{ Money::formatForRecord($invoice->amount_paid, $invoice) }}</b></div>
                    <div class="m2a-kv"><span>{{ __('Balance') }}</span><b class="m2-num">{{ Money::formatForRecord($invoice->balance, $invoice) }}</b></div>
                </div>
            @endif

            <a href="{{ route('m2.slip', $invoice) }}" wire:navigate class="m2-btn" id="m2-bill-slip">{{ __('Open slip') }}</a>
        @endif
    </main>
</div>
