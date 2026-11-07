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

            {{-- Record payment, against the invoice on screen. The form only opens from here, so a Paid invoice never shows it. --}}
            @if ($canPay)
                @if ($payingInvoiceId !== $invoice->id)
                    <button type="button" wire:click="startPay" wire:loading.attr="disabled" wire:target="startPay" class="m2-btn" style="margin-top:12px" id="m2-bill-pay">{{ __('Record payment') }}</button>
                @else
                    <form wire:submit="submitPay" class="m2a-card" style="margin-top:12px" id="m2-bill-pay-form" novalidate>
                        <div>
                            <b style="display:block">{{ __('Record payment') }}</b>
                            <span class="m2-muted m2-sm">{{ __('Balance') }}: <b class="m2-num">{{ Money::formatForRecord($invoice->balance, $invoice) }}</b></span>
                        </div>
                        <div>
                            <label for="m2-pay-amount" class="m2-label">{{ __('Amount') }} ({{ Money::symbolForRecord($invoice) }})</label>
                            <input type="text" inputmode="decimal" id="m2-pay-amount" wire:model="payAmount" class="m2-input is-big" autocomplete="off" enterkeyhint="done">
                            @error('payAmount')<p class="m2-err" id="m2-pay-amount-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="m2-pay-method" class="m2-label">{{ __('Method') }}</label>
                            <select id="m2-pay-method" wire:model="payMethod" class="m2-input">
                                @foreach ($paymentMethods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payMethod')<p class="m2-err">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="m2-pay-note" class="m2-label">{{ __('Note (optional)') }}</label>
                            <input type="text" id="m2-pay-note" wire:model="payNote" class="m2-input" autocomplete="off">
                            @error('payNote')<p class="m2-err">{{ $message }}</p>@enderror
                        </div>
                        <div class="m2-grid2">
                            <button type="button" wire:click="cancelPay" class="m2-btn is-ghost" id="m2-pay-cancel">{{ __('Cancel') }}</button>
                            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="submitPay" id="m2-pay-save">
                                <span wire:loading.remove wire:target="submitPay">{{ __('Save') }}</span>
                                <span wire:loading wire:target="submitPay">{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    </form>
                @endif
            @endif

            <a href="{{ route('m2.slip', $invoice) }}" wire:navigate class="m2-btn {{ $canPay ? 'is-ghost' : '' }}" style="margin-top:12px" id="m2-bill-slip">{{ __('Open slip') }}</a>
        @endif
    </main>
</div>
