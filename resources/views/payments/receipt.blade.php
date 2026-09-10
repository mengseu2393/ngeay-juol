{{--
    Payment receipt PDF (Browsershot primary, dompdf fallback). Two layouts
    switched by $thermal:
      - thermal  : narrow single-column slip (58/65/80 mm rolls) — the cash-in-hand case
      - standard : A4 / A5 sheet
    dompdf supports only a CSS subset: no flexbox/grid — tables are used for ALL
    layout. Money goes through Money::normalize() (0 decimals KHR, 2 USD) and
    dates through Invoice::displayDate(), exactly as invoices/pdf.blade.php does —
    NOT the ad hoc number_format()/'$' the report views use.
    The @font-face pair below is duplicated verbatim from invoices/pdf.blade.php;
    there is no shared partial yet (see CLAUDE.md).
--}}
@php
    /** @var \App\Models\Payment $payment */
    /** @var \App\Models\Invoice|null $invoice */
    use App\Models\Invoice;
    use App\Support\BrandLogo;
    use App\Support\Money;

    $formatPdfMoney = function ($amount, $currency) {
        $currency = Money::normalize($currency);
        $decimals = $currency === 'KHR' ? 0 : 2;
        return $currency === 'KHR'
            ? number_format((float) $amount, $decimals) . ' KHR'
            : '$' . number_format((float) $amount, $decimals);
    };

    $invoiceCurrency = Money::normalize($invoice?->property?->settings?->currency ?? 'USD');
    $money = fn ($v) => $formatPdfMoney($v, $invoiceCurrency);
    // ASCII separator/placeholder: the bundled Khmer font has no en/em dash glyph.
    $date = fn ($d) => Invoice::displayDate($d, 'd M Y', '-');
    $dateTime = fn ($d) => Invoice::displayDate($d, 'd M Y H:i', '-');

    $property = $invoice?->property ?? $invoice?->rental?->unit?->property;
    $business = $property?->name ?? config('app.name');
    $address = $property?->formatted_address;

    $logoDataUri = BrandLogo::dataUri();
    $initials = BrandLogo::fallbackInitials((string) $business);

    $tenantName = $invoice?->tenant?->name ?? $invoice?->rental?->occupant_name ?? '-';
    $roomNumber = $invoice?->rental?->unit?->room_number;

    $paymentCurrency = Money::normalize($payment->currency);
    $rate = (float) ($payment->exchange_rate ?: $invoice?->usd_khr_rate ?: 0);
    // The equivalent line is only meaningful when the payment's own currency
    // differs from the invoice's reporting currency and we have a saved rate.
    $equivalent = ($rate > 0 && $paymentCurrency !== $invoiceCurrency)
        ? $formatPdfMoney($paymentCurrency === 'USD' ? $payment->amount_khr : $payment->amount_usd, $paymentCurrency === 'USD' ? 'KHR' : 'USD')
        : null;

    $balance = $invoice ? (float) $invoice->balance : 0.0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $receiptNumber }}</title>
    <style>
        @font-face {
            font-family: 'NotoSansKhmer';
            font-style: normal;
            font-weight: normal;
            src: url('{{ resource_path('fonts/NotoSansKhmer-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'NotoSansKhmer';
            font-style: normal;
            font-weight: bold;
            src: url('{{ resource_path('fonts/NotoSansKhmer-Bold.ttf') }}') format('truetype');
        }

        * { box-sizing: border-box; }
        html { margin: 0; padding: 0; }
        /* dompdf 3.x ignores @page margins here; page margins are set on <body>
           (which repeats on every page). Thermal receipts stay edge-to-edge. */
        @page { margin: 0; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'NotoSansKhmer', sans-serif;
            color: #0f172a;
            @if ($thermal)
                font-size: 10px;
                line-height: 1.4;
            @else
                margin: 44px 52px;
                font-size: 12px;
                line-height: 1.5;
            @endif
        }
        table { border-collapse: collapse; width: 100%; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #64748b; }
        .bold { font-weight: bold; }

        @if ($thermal)
            /* ---------- Thermal slip (narrow single column) ---------- */
            .wrap { padding: 6px 6px 10px 6px; }
            .brand-table { margin: 0 auto; }
            .brand-table td { vertical-align: middle; }
            .brand-logo { width: 26px; height: 26px; border-radius: 6px; overflow: hidden; background: #fff; border: 1px solid #d1d5db; }
            .brand-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
            .brand-logo span { display: block; line-height: 26px; text-align: center; color: #059669; font-size: 11px; font-weight: bold; }
            .biz { font-size: 13px; font-weight: bold; text-align: center; color: #059669; }
            .biz-addr { font-size: 8px; text-align: center; color: #4b5563; margin-top: 2px; }
            .doc-title { font-size: 11px; font-weight: bold; text-align: center; margin-top: 4px; }
            .doc-no { font-size: 10px; text-align: center; margin-top: 1px; color: #059669; }
            .rule { border-top: 1px dashed #9ca3af; margin: 6px 0; }
            .meta td { padding: 1px 0; font-size: 9px; vertical-align: top; }
            .meta td.k { color: #6b7280; padding-right: 6px; white-space: nowrap; }
            .amount-box { text-align: center; padding: 4px 0; }
            .amount-label { font-size: 9px; color: #6b7280; }
            .amount-value { font-size: 15px; font-weight: bold; color: #059669; }
            .amount-equiv { font-size: 8px; color: #6b7280; }
            .totals td { padding: 1px 0; font-size: 9px; }
            .totals td.amt { text-align: right; white-space: nowrap; }
            .totals tr.grand td { font-size: 10px; font-weight: bold; padding-top: 3px; }
            .notes { font-size: 8px; margin-top: 4px; color: #374151; }
            .sign { font-size: 8px; color: #6b7280; margin-top: 10px; }
            .sign-line { border-top: 1px solid #9ca3af; width: 60%; margin-top: 16px; }
            .thanks { text-align: center; font-size: 10px; margin-top: 8px; }
        @else
            /* ---------- Standard A4 / A5 slip ---------- */
            .logo { width: 34px; height: 34px; background: #ffffff; text-align: center; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
            .logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
            .logo span { display: block; line-height: 34px; font-size: 15px; font-weight: bold; color: #059669; }
            .biz { font-size: 18px; font-weight: bold; color: #0f172a; }
            .biz-addr { font-size: 10px; color: #64748b; margin-top: 3px; max-width: 260px; line-height: 1.4; }
            .doc-label { font-size: 13px; font-weight: bold; color: #94a3b8; text-transform: uppercase; }
            .doc-no { font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 2px; }
            .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-top: 18px; }
            .meta td { padding: 3px 0; font-size: 11px; vertical-align: top; }
            .meta td.k { color: #64748b; padding-right: 14px; white-space: nowrap; width: 34%; }
            .amount-box { border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 8px; padding: 12px 16px; margin-top: 18px; }
            .amount-label { font-size: 11px; color: #047857; }
            .amount-value { font-size: 22px; font-weight: bold; color: #047857; }
            .amount-equiv { font-size: 10px; color: #059669; }
            .totals { margin-top: 18px; }
            .totals td { padding: 4px 0; font-size: 11px; border-bottom: 1px solid #f1f5f9; }
            .totals td.amt { text-align: right; white-space: nowrap; }
            .totals tr.grand td { font-size: 13px; font-weight: bold; border-bottom: none; }
            .notes { font-size: 10px; margin-top: 16px; color: #374151; }
            .sign { font-size: 10px; color: #64748b; margin-top: 34px; }
            .sign-line { border-top: 1px solid #cbd5e1; width: 200px; margin-top: 26px; }
            .thanks { text-align: center; font-size: 11px; margin-top: 26px; color: #64748b; }
        @endif
    </style>
</head>
<body>

@if ($thermal)
    {{-- ========================= THERMAL SLIP ========================= --}}
    <div class="wrap">
        <table class="brand-table">
            <tr>
                <td>
                    <div class="brand-logo">
                        @if ($logoDataUri)
                            <img src="{{ $logoDataUri }}" alt="{{ $business }}">
                        @else
                            <span>{{ $initials }}</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="biz">{{ $business }}</div>
                </td>
            </tr>
        </table>
        @if ($address)
            <div class="biz-addr">{{ $address }}</div>
        @endif

        <div class="doc-title">{{ __('PAYMENT RECEIPT') }}</div>
        <div class="doc-no">{{ $receiptNumber }}</div>

        <div class="rule"></div>

        <table class="meta">
            <tr>
                <td class="k">{{ __('Received from') }}</td>
                <td>{{ $tenantName }}</td>
            </tr>
            @if ($roomNumber)
                <tr>
                    <td class="k">{{ __('Room') }}</td>
                    <td>{{ $roomNumber }}</td>
                </tr>
            @endif
            @if ($invoice)
                <tr>
                    <td class="k">{{ __('Invoice') }}</td>
                    <td>{{ $invoice->invoice_number }}</td>
                </tr>
            @endif
            <tr>
                <td class="k">{{ __('Paid at') }}</td>
                <td>{{ $dateTime($payment->paid_at) }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('Method') }}</td>
                <td>{{ $payment->method?->getLabel() ?? '-' }}</td>
            </tr>
            @if ($payment->transaction_ref)
                <tr>
                    <td class="k">{{ __('Transaction ref') }}</td>
                    <td>{{ $payment->transaction_ref }}</td>
                </tr>
            @endif
            @if ($payment->recordedBy?->name)
                <tr>
                    <td class="k">{{ __('Recorded by') }}</td>
                    <td>{{ $payment->recordedBy->name }}</td>
                </tr>
            @endif
        </table>

        <div class="rule"></div>

        <div class="amount-box">
            <div class="amount-label">{{ __('Amount received') }}</div>
            <div class="amount-value">{{ $formatPdfMoney($payment->amount, $paymentCurrency) }}</div>
            @if ($equivalent)
                <div class="amount-equiv">{{ __('Equivalent') }}: {{ $equivalent }}</div>
            @endif
        </div>

        @if ($invoice)
            <div class="rule"></div>
            <table class="totals">
                <tr>
                    <td>{{ __('Invoice total') }}</td>
                    <td class="amt">{{ $money($invoice->amount_due) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Paid to date') }}</td>
                    <td class="amt">{{ $money($invoice->amount_paid) }}</td>
                </tr>
                <tr class="grand">
                    <td>{{ __('Balance') }}</td>
                    <td class="amt">{{ $money($balance) }}</td>
                </tr>
                @if ($invoice->payment_status)
                    <tr>
                        <td>{{ __('Status') }}</td>
                        <td class="amt">{{ $invoice->payment_status->getLabel() }}</td>
                    </tr>
                @endif
            </table>
        @endif

        @if ($payment->note)
            <div class="rule"></div>
            <div class="notes">{{ $payment->note }}</div>
        @endif

        <div class="thanks">{{ __('Thank you') }}</div>
    </div>

@else
    {{-- ========================= STANDARD SLIP ========================= --}}
    <table>
        <tr>
            <td style="vertical-align: top;">
                <table>
                    <tr>
                        <td style="width: 34px; vertical-align: top;">
                            <div class="logo">
                                @if ($logoDataUri)
                                    <img src="{{ $logoDataUri }}" alt="{{ $business }}">
                                @else
                                    <span>{{ $initials }}</span>
                                @endif
                            </div>
                        </td>
                        <td style="vertical-align: top; padding-left: 11px;">
                            <div class="biz">{{ $business }}</div>
                            @if ($address)
                                <div class="biz-addr">{{ $address }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div class="doc-label">{{ __('Payment receipt') }}</div>
                <div class="doc-no">{{ $receiptNumber }}</div>
            </td>
        </tr>
    </table>

    <div class="card">
        <table class="meta">
            <tr>
                <td class="k">{{ __('Received from') }}</td>
                <td>{{ $tenantName }}</td>
            </tr>
            @if ($roomNumber)
                <tr>
                    <td class="k">{{ __('Room') }}</td>
                    <td>{{ $roomNumber }}</td>
                </tr>
            @endif
            @if ($invoice)
                <tr>
                    <td class="k">{{ __('Invoice') }}</td>
                    <td>{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Billing period') }}</td>
                    <td>{{ $invoice->billingPeriodLabel('d M Y', ' - ', '-') }}</td>
                </tr>
            @endif
            <tr>
                <td class="k">{{ __('Paid at') }}</td>
                <td>{{ $dateTime($payment->paid_at) }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('Method') }}</td>
                <td>{{ $payment->method?->getLabel() ?? '-' }}</td>
            </tr>
            @if ($payment->transaction_ref)
                <tr>
                    <td class="k">{{ __('Transaction ref') }}</td>
                    <td>{{ $payment->transaction_ref }}</td>
                </tr>
            @endif
            @if ($payment->recordedBy?->name)
                <tr>
                    <td class="k">{{ __('Recorded by') }}</td>
                    <td>{{ $payment->recordedBy->name }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="amount-box">
        <table>
            <tr>
                <td style="vertical-align: middle;">
                    <div class="amount-label">{{ __('Amount received') }}</div>
                    @if ($equivalent)
                        <div class="amount-equiv">{{ __('Equivalent') }}: {{ $equivalent }}</div>
                    @endif
                </td>
                <td style="vertical-align: middle; text-align: right;">
                    <div class="amount-value">{{ $formatPdfMoney($payment->amount, $paymentCurrency) }}</div>
                </td>
            </tr>
        </table>
    </div>

    @if ($invoice)
        <table class="totals">
            <tr>
                <td>{{ __('Invoice total') }}</td>
                <td class="amt">{{ $money($invoice->amount_due) }}</td>
            </tr>
            <tr>
                <td>{{ __('Paid to date') }}</td>
                <td class="amt">{{ $money($invoice->amount_paid) }}</td>
            </tr>
            <tr class="grand">
                <td>{{ __('Balance') }}</td>
                <td class="amt">{{ $money($balance) }}</td>
            </tr>
            @if ($invoice->payment_status)
                <tr>
                    <td>{{ __('Status') }}</td>
                    <td class="amt">{{ $invoice->payment_status->getLabel() }}</td>
                </tr>
            @endif
        </table>
    @endif

    @if ($payment->note)
        <div class="notes">{{ $payment->note }}</div>
    @endif

    <div class="sign">
        <div class="sign-line"></div>
        {{ __('Received by') }}
    </div>

    <div class="thanks">{{ __('Thank you') }}</div>
@endif

</body>
</html>
