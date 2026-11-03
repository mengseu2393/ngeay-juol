@php
    use App\Support\Money;

    $currency = Money::forRecord($invoice);
@endphp

<div class="m2a">
    <x-m2.header :title="$invoice->property?->name ?? __('Slip')" :subtitle="$unit ? __('Room').' '.$unit->room_number : null" :back="$unit ? route('m2.bill', $unit) : route('m2.home')" />

    <main class="m2-wrap m2-stack">
        <article class="m2-slip" id="m2-slip">
            <h2>{{ $invoice->property?->name }}</h2>
            <p class="m2-sub">{{ $invoice->invoice_number }}</p>

            <div style="margin-top:14px">
                <div class="m2-meta"><span>{{ __('Room') }}</span><b>{{ $unit?->room_number ?? '—' }}</b></div>
                <div class="m2-meta"><span>{{ __('Tenant') }}</span><b>{{ $invoice->rental?->occupant_name ?? '—' }}</b></div>
                <div class="m2-meta"><span>{{ __('Period') }}</span><b>{{ $invoice->billingPeriodLabel('d M Y') }}</b></div>
                <div class="m2-meta"><span>{{ __('Due date') }}</span><b>{{ \App\Models\Invoice::displayDate($invoice->due_date) }}</b></div>
            </div>

            <table>
                @foreach ($lines as $line)
                    <tr wire:key="m2-slip-line-{{ $line->id }}">
                        <td>{{ $line->getTranslatedDescription() }}</td>
                        <td>{{ Money::format($line->amount, $line->currency ?: $currency) }}</td>
                    </tr>
                @endforeach
                <tr class="is-total"><td>{{ __('Total') }}</td><td id="m2-slip-total">{{ Money::format($invoice->amount_due, $currency) }}</td></tr>
                @if ((float) $invoice->amount_paid > 0)
                    <tr><td>{{ __('Paid') }}</td><td>{{ Money::format($invoice->amount_paid, $currency) }}</td></tr>
                    <tr><td>{{ __('Balance') }}</td><td>{{ Money::format($invoice->balance, $currency) }}</td></tr>
                @endif
            </table>

            <p class="m2-sub" style="margin-top:14px">{{ $invoice->payment_status->getLabel() }}</p>
        </article>

        <div class="m2-grid2 m2-noprint">
            <button type="button" class="m2-btn is-ghost" onclick="window.print()" id="m2-slip-print">{{ __('Print') }}</button>
            <a href="{{ route('invoices.pdf', ['invoice' => $invoice->id, 'mode' => 'stream']) }}" class="m2-btn" id="m2-slip-pdf">{{ __('PDF') }}</a>
        </div>
    </main>
</div>
