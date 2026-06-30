{{--
    Invoice / receipt PDF (dompdf). Two layouts switched by $thermal:
      - thermal  : narrow single-column receipt (80mm / 65mm rolls)
      - standard : professional A4 / A5 invoice
    dompdf supports only a CSS subset: no flexbox/grid — tables are used for
    all layout. Money is '$' . number_format(..., 2); dates are null-safe.
--}}
@php
    /** @var \App\Models\Invoice $invoice */
    $money = fn ($v) => '$' . number_format((float) $v, 2);
    $date = fn ($d) => $d ? $d->format('d M Y') : '—';

    $business = optional($invoice->property)->name
        ?? optional(optional($invoice->rental)->unit?->property)->name
        ?? config('app.name');

    // Tenant / room display, falling back across the relation chain.
    $tenantName = optional($invoice->tenant)->name
        ?? optional($invoice->rental)->occupant_name
        ?? '—';
    $roomNumber = optional(optional($invoice->rental)->unit)->room_number;

    // Assemble the property's postal address from whatever parts exist.
    $property = $invoice->property ?? optional($invoice->rental)->unit?->property;
    $addressParts = [];
    if ($property) {
        foreach (['address_line', 'street', 'village', 'commune', 'district', 'city', 'postal_code'] as $part) {
            if (! empty($property->{$part})) {
                $addressParts[] = $property->{$part};
            }
        }
    }
    $address = implode(', ', $addressParts);

    $lines = $invoice->lines;
    $payments = $invoice->payments;

    $subtotal = $lines->sum(fn ($l) => (float) $l->amount);
    $status = optional($invoice->payment_status)->getLabel();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
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
        html, body { margin: 0; padding: 0; }
        @if ($thermal)
            /* Receipts print edge-to-edge on narrow rolls. */
            @page { margin: 0; }
        @else
            /* A4 / A5 get real printable margins on every side. */
            @page { margin: 36px 40px; }
        @endif
        body {
            font-family: 'NotoSansKhmer', sans-serif;
            color: #1f2937;
            @if ($thermal)
                font-size: 10px;
                line-height: 1.4;
            @else
                font-size: 12px;
                line-height: 1.5;
            @endif
        }
        table { border-collapse: collapse; width: 100%; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #6b7280; }
        .bold { font-weight: bold; }

        @if ($thermal)
            /* ---------- Thermal receipt (narrow single column) ---------- */
            .wrap { padding: 6px 6px 10px 6px; }
            .biz { font-size: 13px; font-weight: bold; text-align: center; }
            .biz-addr { font-size: 8px; text-align: center; color: #4b5563; margin-top: 2px; }
            .doc-title { font-size: 11px; font-weight: bold; text-align: center; margin-top: 4px; letter-spacing: 1px; }
            .doc-no { font-size: 10px; text-align: center; margin-top: 1px; }
            .rule { border-top: 1px dashed #9ca3af; margin: 6px 0; }
            .meta td { padding: 1px 0; font-size: 9px; vertical-align: top; }
            .meta td.k { color: #6b7280; padding-right: 6px; white-space: nowrap; }
            .items td { padding: 2px 0; vertical-align: top; font-size: 9px; }
            .items .desc { word-wrap: break-word; }
            .items .amt { text-align: right; white-space: nowrap; padding-left: 6px; }
            .qty { color: #6b7280; font-size: 8px; }
            .totals td { padding: 1px 0; font-size: 9px; }
            .totals td.amt { text-align: right; }
            .totals tr.grand td { font-size: 11px; font-weight: bold; padding-top: 3px; }
            .pay td { padding: 1px 0; font-size: 8px; vertical-align: top; }
            .pay td.amt { text-align: right; white-space: nowrap; }
            .notes { font-size: 8px; margin-top: 4px; color: #374151; }
            .thanks { text-align: center; font-size: 10px; margin-top: 8px; }
            .waived { color: #6b7280; font-size: 8px; }
        @else
            /* ---------- Standard A4 / A5 invoice ---------- */
            .page { padding: 4px 0; }
            .header-tbl td { vertical-align: top; }
            .biz { font-size: 18px; font-weight: bold; color: #111827; }
            .biz-addr { font-size: 11px; color: #6b7280; margin-top: 4px; max-width: 280px; }
            .doc-label { font-size: 22px; font-weight: bold; color: #111827; letter-spacing: 1px; }
            .doc-no { font-size: 13px; color: #374151; margin-top: 2px; }
            .status {
                display: inline-block;
                margin-top: 6px;
                padding: 3px 10px;
                border: 1px solid #d1d5db;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                color: #374151;
                background: #f3f4f6;
            }
            .section-title { font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
            .billto { font-size: 13px; font-weight: bold; color: #111827; }
            .billto-sub { font-size: 11px; color: #4b5563; margin-top: 2px; }
            .meta-tbl { margin-top: 18px; border: 1px solid #e5e7eb; }
            .meta-tbl td { padding: 8px 12px; font-size: 11px; border-right: 1px solid #e5e7eb; width: 33%; }
            .meta-tbl td:last-child { border-right: 0; }
            .meta-tbl .k { color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 2px; }
            .items-tbl { margin-top: 18px; }
            .items-tbl th {
                background: #111827; color: #ffffff; font-size: 10px; font-weight: bold;
                text-transform: uppercase; letter-spacing: .5px; padding: 8px 10px; text-align: left;
            }
            .items-tbl th.num, .items-tbl td.num { text-align: right; }
            .items-tbl td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; vertical-align: top; }
            .items-tbl tr.waived td { color: #9ca3af; }
            .waived-tag { font-style: italic; color: #9ca3af; font-size: 10px; }
            .totals-tbl { margin-top: 14px; }
            .totals-tbl td { padding: 5px 10px; font-size: 12px; }
            .totals-tbl td.k { text-align: right; color: #4b5563; }
            .totals-tbl td.v { text-align: right; width: 120px; }
            .totals-tbl tr.grand td { font-size: 14px; font-weight: bold; color: #111827; border-top: 2px solid #111827; }
            .totals-tbl tr.balance td { font-weight: bold; }
            .pay-tbl { margin-top: 22px; }
            .pay-tbl th {
                background: #f3f4f6; color: #374151; font-size: 10px; font-weight: bold;
                text-transform: uppercase; letter-spacing: .5px; padding: 6px 10px; text-align: left;
                border-bottom: 1px solid #d1d5db;
            }
            .pay-tbl th.num, .pay-tbl td.num { text-align: right; }
            .pay-tbl td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
            .notes-box { margin-top: 22px; border: 1px solid #e5e7eb; background: #f9fafb; padding: 10px 12px; font-size: 11px; color: #374151; }
            .footer { margin-top: 28px; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        @endif
    </style>
</head>
<body>

@if ($thermal)
    {{-- ========================= THERMAL RECEIPT ========================= --}}
    <div class="wrap">
        <div class="biz">{{ $business }}</div>
        @if ($address)
            <div class="biz-addr">{{ $address }}</div>
        @endif

        <div class="doc-title">{{ __('RECEIPT') }}</div>
        <div class="doc-no">{{ $invoice->invoice_number }}</div>

        <div class="rule"></div>

        <table class="meta">
            <tr>
                <td class="k">{{ __('Bill to') }}</td>
                <td>{{ $tenantName }}</td>
            </tr>
            @if ($roomNumber)
                <tr>
                    <td class="k">{{ __('Room') }}</td>
                    <td>{{ $roomNumber }}</td>
                </tr>
            @endif
            @if ($invoice->period_start || $invoice->period_end)
                <tr>
                    <td class="k">{{ __('Period') }}</td>
                    <td>{{ $date($invoice->period_start) }} – {{ $date($invoice->period_end) }}</td>
                </tr>
            @endif
            <tr>
                <td class="k">{{ __('Issued') }}</td>
                <td>{{ $date($invoice->issue_date) }}</td>
            </tr>
            @if ($status)
                <tr>
                    <td class="k">{{ __('Status') }}</td>
                    <td>{{ $status }}</td>
                </tr>
            @endif
        </table>

        <div class="rule"></div>

        {{-- Line items: description + qty on one row, amount aligned right --}}
        <table class="items">
            @foreach ($lines as $line)
                <tr>
                    <td class="desc">
                        {{ $line->description }}
                        @if ($line->is_waived)
                            <span class="waived">({{ __('Waived') }})</span>
                        @endif
                        @if ((float) $line->quantity != 1.0)
                            <div class="qty">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }} × {{ $money($line->unit_price) }}</div>
                        @endif
                    </td>
                    <td class="amt">{{ $line->is_waived ? $money(0) : $money($line->amount) }}</td>
                </tr>
            @endforeach
        </table>

        <div class="rule"></div>

        <table class="totals">
            <tr>
                <td>{{ __('Subtotal') }}</td>
                <td class="amt">{{ $money($subtotal) }}</td>
            </tr>
            <tr class="grand">
                <td>{{ __('Total due') }}</td>
                <td class="amt">{{ $money($invoice->amount_due) }}</td>
            </tr>
            <tr>
                <td>{{ __('Paid') }}</td>
                <td class="amt">{{ $money($invoice->amount_paid) }}</td>
            </tr>
            <tr>
                <td class="bold">{{ __('Balance') }}</td>
                <td class="amt bold">{{ $money($invoice->balance) }}</td>
            </tr>
        </table>

        @if ($payments->isNotEmpty())
            <div class="rule"></div>
            <div class="bold" style="font-size: 9px;">{{ __('Payments') }}</div>
            <table class="pay">
                @foreach ($payments as $payment)
                    <tr>
                        <td>
                            {{ optional($payment->paid_at)->format('d M Y') }}
                            · {{ optional($payment->method)->getLabel() }}
                            @if ($payment->receipt_number)
                                · {{ $payment->receipt_number }}
                            @endif
                        </td>
                        <td class="amt">{{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($invoice->notes)
            <div class="rule"></div>
            <div class="notes">{{ $invoice->notes }}</div>
        @endif

        <div class="thanks">{{ __('Thank you') }}</div>
    </div>

@else
    {{-- ========================= STANDARD INVOICE ========================= --}}
    <div class="page">

        {{-- Header: business / address on the left, doc title + status on the right --}}
        <table class="header-tbl">
            <tr>
                <td>
                    <div class="biz">{{ $business }}</div>
                    @if ($address)
                        <div class="biz-addr">{{ $address }}</div>
                    @endif
                </td>
                <td class="right">
                    <div class="doc-label">{{ __('Invoice') }}</div>
                    <div class="doc-no">{{ $invoice->invoice_number }}</div>
                    @if ($status)
                        <div class="status">{{ $status }}</div>
                    @endif
                </td>
            </tr>
        </table>

        {{-- Bill to --}}
        <table style="margin-top: 22px;">
            <tr>
                <td>
                    <div class="section-title">{{ __('Bill to') }}</div>
                    <div class="billto">{{ $tenantName }}</div>
                    @if ($roomNumber)
                        <div class="billto-sub">{{ __('Room') }} {{ $roomNumber }}</div>
                    @endif
                </td>
            </tr>
        </table>

        {{-- Meta row: Period / Issued / Due --}}
        <table class="meta-tbl">
            <tr>
                <td>
                    <span class="k">{{ __('Period') }}</span>
                    {{ $date($invoice->period_start) }} – {{ $date($invoice->period_end) }}
                </td>
                <td>
                    <span class="k">{{ __('Issued') }}</span>
                    {{ $date($invoice->issue_date) }}
                </td>
                <td>
                    <span class="k">{{ __('Due') }}</span>
                    {{ $date($invoice->due_date) }}
                </td>
            </tr>
        </table>

        {{-- Line items --}}
        <table class="items-tbl">
            <thead>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Qty') }}</th>
                    <th class="num">{{ __('Unit price') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr class="{{ $line->is_waived ? 'waived' : '' }}">
                        <td>
                            {{ $line->description }}
                            @if ($line->line_type)
                                <div class="muted" style="font-size: 9px;">{{ optional($line->line_type)->getLabel() }}</div>
                            @endif
                            @if ($line->is_waived)
                                <span class="waived-tag">({{ __('Waived') }})</span>
                            @endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                        <td class="num">{{ $money($line->unit_price) }}</td>
                        <td class="num">{{ $line->is_waived ? $money(0) : $money($line->amount) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="muted center" style="padding: 16px;">{{ __('No line items.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals, right-aligned --}}
        <table class="totals-tbl">
            <tr>
                <td></td>
                <td>
                    <table>
                        <tr>
                            <td class="k">{{ __('Subtotal') }}</td>
                            <td class="v">{{ $money($subtotal) }}</td>
                        </tr>
                        <tr class="grand">
                            <td class="k">{{ __('Total due') }}</td>
                            <td class="v">{{ $money($invoice->amount_due) }}</td>
                        </tr>
                        <tr>
                            <td class="k">{{ __('Paid') }}</td>
                            <td class="v">{{ $money($invoice->amount_paid) }}</td>
                        </tr>
                        <tr class="balance">
                            <td class="k">{{ __('Balance') }}</td>
                            <td class="v">{{ $money($invoice->balance) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- Payments --}}
        @if ($payments->isNotEmpty())
            <div class="section-title" style="margin-top: 22px;">{{ __('Payments') }}</div>
            <table class="pay-tbl">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Method') }}</th>
                        <th>{{ __('Receipt') }}</th>
                        <th class="num">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ optional($payment->paid_at)->format('d M Y') }}</td>
                            <td>{{ optional($payment->method)->getLabel() ?? '—' }}</td>
                            <td>{{ $payment->receipt_number ?? $payment->transaction_ref ?? '—' }}</td>
                            <td class="num">{{ $money($payment->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Notes --}}
        @if ($invoice->notes)
            <div class="notes-box">
                <span class="section-title">{{ __('Notes') }}</span><br>
                {{ $invoice->notes }}
            </div>
        @endif

        <div class="footer">
            {{ $business }} · {{ $invoice->invoice_number }} · {{ __('Thank you') }}
        </div>
    </div>
@endif

</body>
</html>
