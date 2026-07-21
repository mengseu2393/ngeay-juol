{{--
    Invoice / receipt PDF (dompdf). Two layouts switched by $thermal:
      - thermal  : narrow single-column receipt (80mm / 65mm rolls)
      - standard : professional A4 / A5 invoice
    dompdf supports only a CSS subset: no flexbox/grid — tables are used for
    all layout. Money is '$' . number_format(..., 2); dates are validated +
    formatted via Invoice::displayDate() so corrupt values (e.g. year 0011)
    never print as a broken range. The address is de-duplicated by the Property
    model so it isn't repeated field-by-field.
--}}
@php
    /** @var \App\Models\Invoice $invoice */
    use App\Models\Invoice;
    use App\Support\BrandLogo;
    use App\Support\Money;

    $formatPdfMoney = function ($amount, $currency) {
        $currency = \App\Support\Money::normalize($currency);
        $decimals = $currency === 'KHR' ? 0 : 2;
        return $currency === 'KHR'
            ? number_format((float) $amount, $decimals) . ' KHR'
            : '$' . number_format((float) $amount, $decimals);
    };

    $money = fn ($v) => $formatPdfMoney($v, $invoice->property?->settings?->currency ?? 'USD');
    // ASCII separator/placeholder: the bundled Khmer font has no en/em dash glyph.
    $date = fn ($d) => Invoice::displayDate($d, 'd M Y', '-');
    $period = $invoice->billingPeriodLabel('d M Y', ' - ', '-');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    $property = $invoice->property ?? $invoice->rental?->unit?->property;
    $business = $property?->name ?? config('app.name');
    $address = $property?->formatted_address;

    $logoDataUri = BrandLogo::dataUri();
    $initials = BrandLogo::fallbackInitials((string) $business);

    // Tenant / room display, falling back across the relation chain.
    $tenantName = $invoice->tenant?->name ?? $invoice->rental?->occupant_name ?? '—';
    $roomNumber = $invoice->rental?->unit?->room_number;

    $lines = $invoice->lines;
    $visibleLines = $lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice());
    $payments = $invoice->payments;

    $subtotal = $visibleLines->sum(fn ($l) => (float) $l->amount);
    $status = $invoice->payment_status?->getLabel();
    $owing = (float) $invoice->balance > 0.005;

    // Status pill palette keyed by the enum's Filament colour name.
    $palette = [
        'success' => ['bg' => '#d1fae5', 'fg' => '#065f46', 'bd' => '#a7f3d0'],
        'warning' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fde68a'],
        'info'    => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'bd' => '#bfdbfe'],
        'danger'  => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'bd' => '#fecaca'],
        'gray'    => ['bg' => '#f1f5f9', 'fg' => '#475569', 'bd' => '#e2e8f0'],
    ];
    $badge = $palette[$invoice->payment_status?->getColor() ?? 'gray'] ?? $palette['gray'];
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
            /* ---------- Thermal receipt (narrow single column) ---------- */
            .wrap { padding: 6px 6px 10px 6px; }
            .brand { text-align: center; }
            .brand-table { margin: 0 auto; }
            .brand-table td { vertical-align: middle; }
            .brand-logo { width: 26px; height: 26px; border-radius: 6px; overflow: hidden; background: #fff; border: 1px solid #d1d5db; }
            .brand-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
            .brand-logo span { display: block; line-height: 26px; text-align: center; color: #059669; font-size: 11px; font-weight: bold; }
            .biz { font-size: 13px; font-weight: bold; text-align: center; color: #059669; }
            .biz-addr { font-size: 8px; text-align: center; color: #4b5563; margin-top: 2px; }
            .doc-title { font-size: 11px; font-weight: bold; text-align: center; margin-top: 4px; letter-spacing: 0; }
            .doc-no { font-size: 10px; text-align: center; margin-top: 1px; color: #059669; }
            .rule { border-top: 1px dashed #9ca3af; margin: 6px 0; }
            .meta td { padding: 1px 0; font-size: 9px; vertical-align: top; }
            .meta td.k { color: #6b7280; padding-right: 6px; white-space: nowrap; }
            .items td { padding: 2px 0; vertical-align: top; font-size: 9px; }
            .items .desc { word-wrap: break-word; }
            .items .amt { text-align: right; white-space: nowrap; padding-left: 6px; }
            .qty { color: #6b7280; font-size: 8px; }
            .totals td { padding: 1px 0; font-size: 9px; }
            .totals td.amt { text-align: right; }
            .totals tr.grand td { font-size: 11px; font-weight: bold; padding-top: 3px; color: #059669; }
            .pay td { padding: 1px 0; font-size: 8px; vertical-align: top; }
            .pay td.amt { text-align: right; white-space: nowrap; }
            .notes { font-size: 8px; margin-top: 4px; color: #374151; }
            .thanks { text-align: center; font-size: 10px; margin-top: 8px; }
            .waived { color: #6b7280; font-size: 8px; }
        @else
            /* ---------- Standard A4 / A5 invoice ---------- */
            .logo { width: 34px; height: 34px; background: #ffffff; text-align: center; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
            .logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
            .logo span { display: block; line-height: 34px; font-size: 15px; font-weight: bold; color: #059669; }
            .biz { font-size: 18px; font-weight: bold; color: #0f172a; letter-spacing: 0; }
            .biz-addr { font-size: 10px; color: #64748b; margin-top: 3px; max-width: 260px; line-height: 1.4; }
            .doc-label { font-size: 13px; font-weight: bold; color: #94a3b8; letter-spacing: 0; text-transform: uppercase; }
            .doc-no { font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 2px; }
            .status {
                display: inline-block;
                margin-top: 7px;
                padding: 3px 11px;
                border-radius: 9999px;
                font-size: 9px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0;
            }
            .head-rule { border-bottom: 1px solid #e2e8f0; }

            .section-title { font-size: 9px; font-weight: bold; color: #059669; text-transform: uppercase; letter-spacing: 0; margin-bottom: 5px; }
            .billto { font-size: 14px; font-weight: bold; color: #0f172a; }
            .billto-sub { font-size: 11px; color: #475569; margin-top: 3px; }
            .room-tag { display: inline-block; margin-top: 4px; padding: 1px 8px; background: #ecfdf5; color: #047857; border-radius: 5px; font-size: 10px; font-weight: bold; }

            .defs td { padding: 3px 0; font-size: 11px; vertical-align: top; }
            .defs td.dk { color: #64748b; padding-right: 14px; white-space: nowrap; }
            .defs td.dv { text-align: right; font-weight: bold; color: #0f172a; }
            .defs td.dv.due { color: #059669; }

            .items-tbl { margin-top: 24px; }
            .items-tbl thead th {
                color: #64748b; font-size: 9px; font-weight: bold;
                text-transform: uppercase; letter-spacing: 0; padding: 0 10px 7px; text-align: left;
                border-bottom: 2px solid #059669;
            }
            .items-tbl thead th.num { text-align: right; }
            .items-tbl tbody td { padding: 9px 10px; border-bottom: 1px solid #eef2f7; font-size: 11px; vertical-align: top; }
            .items-tbl tbody td.num { text-align: right; white-space: nowrap; }
            .items-tbl .desc { font-weight: bold; color: #0f172a; }
            .items-tbl .amt { font-weight: bold; color: #0f172a; }
            .items-tbl tr.waived .desc, .items-tbl tr.waived .amt { color: #94a3b8; }
            .line-type { color: #94a3b8; font-size: 9px; margin-top: 2px; }
            .waived-tag { font-style: italic; color: #94a3b8; font-size: 9px; }
            .usage { color: #64748b; font-size: 9px; margin-top: 3px; line-height: 1.35; }

            .totals-tbl { width: 100%; }
            .totals-tbl td { padding: 5px 10px; font-size: 12px; }
            .totals-tbl td.k { color: #64748b; }
            .totals-tbl td.v { text-align: right; font-weight: 600; color: #0f172a; }
            .totals-tbl tr.grand td { border-top: 1px solid #e2e8f0; padding-top: 8px; }
            .totals-tbl tr.grand td.k { font-weight: bold; color: #0f172a; font-size: 13px; }
            .totals-tbl tr.grand td.v { font-weight: bold; color: #059669; font-size: 13px; }
            .totals-tbl tr.balance td { background: #ecfdf5; font-weight: bold; padding-top: 8px; padding-bottom: 8px; }
            .totals-tbl tr.balance td.k { color: #065f46; }
            .totals-tbl tr.balance td.v { color: #065f46; font-size: 15px; }
            .totals-tbl tr.balance.owing td { background: #fef2f2; }
            .totals-tbl tr.balance.owing td.k, .totals-tbl tr.balance.owing td.v { color: #991b1b; }

            .pay-tbl { margin-top: 26px; }
            .pay-tbl thead th {
                color: #64748b; font-size: 9px; font-weight: bold;
                text-transform: uppercase; letter-spacing: 0; padding: 0 10px 6px; text-align: left;
                border-bottom: 1px solid #e2e8f0;
            }
            .pay-tbl thead th.num { text-align: right; }
            .pay-tbl tbody td { padding: 7px 10px; border-bottom: 1px solid #eef2f7; font-size: 11px; }
            .pay-tbl tbody td.num { text-align: right; white-space: nowrap; }
            .pay-tbl tbody tr:last-child td { border-bottom: 0; }

            .notes-box { margin-top: 26px; border: 1px solid #eef2f7; border-left: 3px solid #059669; background: #f8fafc; padding: 10px 12px; font-size: 11px; color: #334155; border-radius: 5px; }
            .footer { margin-top: 34px; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; }
        @endif
    </style>
</head>
<body>

@if ($thermal)
    {{-- ========================= THERMAL RECEIPT ========================= --}}
    <div class="wrap">
        <div class="brand">
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
        </div>
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
            <tr>
                <td class="k">{{ __('Period') }}</td>
                <td>{{ $period }}</td>
            </tr>
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

        <table class="items">
            @foreach ($visibleLines as $line)
                <tr>
                    <td class="desc">
                        {{ $line->getTranslatedDescription() }}
                        @if ($line->resolvedChargeState() === 'custom')
                            <span class="line-type">({{ __('Adjusted') }})</span>
                        @elseif ($line->resolvedChargeState() === 'free')
                            <span class="waived">({{ __('Free') }})</span>
                        @elseif ($line->resolvedChargeState() === 'waived')
                            <span class="waived">({{ __('Waived') }})</span>
                        @endif
                        @if ((float) $line->quantity != 1.0)
                            <div class="qty">{{ $qty($line->quantity) }} × {{ $formatPdfMoney($line->unit_price, $line->currency) }}</div>
                        @endif
                    </td>
                    <td class="amt">
                        @if ($line->resolvedChargeState() === 'free')
                            {{ __('Free') }}
                        @elseif ($line->resolvedChargeState() === 'waived')
                            {{ __('Waived') }}
                            @if ($line->resolvedChargeStateReason())
                                <div class="waived-tag">{{ $line->resolvedChargeStateReason() }}</div>
                            @endif
                        @else
                            {{ $formatPdfMoney($line->amount, $line->currency) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>

        <div class="rule"></div>

        <table class="totals">
            @if ($invoice->usd_khr_rate > 0)
                <tr>
                    <td>{{ __('USD charges') }}</td>
                    <td class="amt">{{ $formatPdfMoney($invoice->native_usd_total, 'USD') }}</td>
                </tr>
                <tr>
                    <td>{{ __('KHR charges') }}</td>
                    <td class="amt">{{ $formatPdfMoney($invoice->native_khr_total, 'KHR') }}</td>
                </tr>
                @php
                    $reportingCurrency = Money::normalize($invoice->property?->settings?->currency ?? 'USD');
                    $primaryTotal = $reportingCurrency === 'KHR' ? $invoice->total_khr : $invoice->total_usd;
                    $primaryCurrency = $reportingCurrency;
                    $equivTotal = $reportingCurrency === 'KHR' ? $invoice->total_usd : $invoice->total_khr;
                    $equivCurrency = $reportingCurrency === 'KHR' ? 'USD' : 'KHR';
                @endphp
                <tr class="grand">
                    <td>{{ __('Total due') }}</td>
                    <td class="amt">{{ $formatPdfMoney($primaryTotal, $primaryCurrency) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Equivalent') }}</td>
                    <td class="amt">{{ $formatPdfMoney($equivTotal, $equivCurrency) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Exchange rate') }}</td>
                    <td class="amt">1 USD = {{ number_format($invoice->usd_khr_rate, 0) }} KHR</td>
                </tr>
                <tr>
                    <td>{{ __('Paid') }}</td>
                    <td class="amt">
                        {{ $formatPdfMoney($invoice->paid_usd, 'USD') }} / {{ $formatPdfMoney($invoice->paid_khr, 'KHR') }}
                    </td>
                </tr>
                <tr class="bold">
                    <td>{{ __('Balance') }}</td>
                    <td class="amt">
                        {{ $formatPdfMoney($invoice->balance_usd, 'USD') }} / {{ $formatPdfMoney($invoice->balance_khr, 'KHR') }}
                    </td>
                </tr>
            @else
                <tr>
                    <td>{{ __('Subtotal') }}</td>
                    <td class="amt">{{ $formatPdfMoney($subtotal, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                </tr>
                <tr class="grand">
                    <td>{{ __('Total due') }}</td>
                    <td class="amt">{{ $formatPdfMoney($invoice->amount_due, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                </tr>
                <tr>
                    <td>{{ __('Paid') }}</td>
                    <td class="amt">{{ $formatPdfMoney($invoice->amount_paid, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                </tr>
                <tr>
                    <td class="bold">{{ __('Balance') }}</td>
                    <td class="amt bold">{{ $formatPdfMoney($invoice->balance, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="muted right" style="font-size: 8px; padding-top: 3px;">
                        {{ __('Exchange-rate snapshot unavailable') }}
                    </td>
                </tr>
            @endif
        </table>

        @if ($payments->isNotEmpty())
            <div class="rule"></div>
            <div class="bold" style="font-size: 9px; color: #059669;">{{ __('Payments') }}</div>
            <table class="pay">
                @foreach ($payments as $payment)
                    <tr>
                        <td>
                            {{ $date($payment->paid_at) }}
                            · {{ optional($payment->method)->getLabel() }}
                            @if ($payment->receipt_number)
                                · {{ $payment->receipt_number }}
                            @endif
                        </td>
                        <td class="amt">{{ $formatPdfMoney($payment->amount, $payment->currency) }}</td>
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

        {{-- Header --}}
        <table class="header-tbl">
            <tr>
                <td style="vertical-align: top; padding-bottom: 18px;">
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
                <td class="right" style="vertical-align: top; padding-bottom: 18px;">
                    <div class="doc-label">{{ __('Invoice') }}</div>
                    <div class="doc-no">{{ $invoice->invoice_number }}</div>
                    @if ($status)
                        <div class="status" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }}; border: 1px solid {{ $badge['bd'] }};">{{ $status }}</div>
                    @endif
                </td>
            </tr>
        </table>
        <div class="head-rule"></div>

        {{-- Bill to + billing meta --}}
        <table style="margin-top: 20px;">
            <tr>
                <td style="width: 55%; vertical-align: top; padding-right: 16px;">
                    <div class="section-title">{{ __('Bill to') }}</div>
                    <div class="billto">{{ $tenantName }}</div>
                    @if ($roomNumber)
                        <div><span class="room-tag">{{ __('Room') }} {{ $roomNumber }}</span></div>
                    @endif
                    @if ($invoice->tenant?->phone_number)
                        <div class="billto-sub">{{ $invoice->tenant->phone_number }}</div>
                    @endif
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table class="defs">
                        <tr>
                            <td class="dk">{{ __('Billing Period') }}</td>
                            <td class="dv">{{ $period }}</td>
                        </tr>
                        <tr>
                            <td class="dk">{{ __('Issued Date') }}</td>
                            <td class="dv">{{ $date($invoice->issue_date) }}</td>
                        </tr>
                        <tr>
                            <td class="dk">{{ __('Due Date') }}</td>
                            <td class="dv due">{{ $date($invoice->due_date) }}</td>
                        </tr>
                    </table>
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
                @forelse ($visibleLines as $line)
                    @php $usage = $line->utilityUsage; @endphp
                    <tr class="{{ $line->resolvedChargeState() === 'waived' ? 'waived' : '' }}">
                        <td>
                            <div class="desc">{{ $line->getTranslatedDescription() }}</div>
                            @if ($line->line_type)
                                <div class="line-type">{{ optional($line->line_type)->getLabel() }}</div>
                            @endif
                            @if ($line->resolvedChargeState() === 'free')
                                <span class="waived-tag">({{ __('Free') }})</span>
                            @elseif ($line->resolvedChargeState() === 'waived')
                                <span class="waived-tag">({{ __('Waived') }})</span>
                            @elseif ($line->resolvedChargeState() === 'custom')
                                <span class="waived-tag">({{ __('Adjusted') }})</span>
                            @endif
                            {{-- Only show meter details when both readings exist, matching the on-screen modal (no fabricated "0.0 → 0.0"). --}}
                            @if ($usage && $usage->propertyUtility && $usage->old_reading !== null && $usage->new_reading !== null)
                                @php $pu = $usage->propertyUtility; @endphp
                                <div class="usage">
                                    {{-- The Khmer PDF font lacks U+2192; render the arrow with DejaVu Sans (bundled with dompdf) so it isn't a tofu box. --}}
                                    {{ __('Meter') }}: {{ number_format((float) $usage->old_reading, 1) }} <span style="font-family: 'DejaVu Sans';">&#8594;</span> {{ number_format((float) $usage->new_reading, 1) }}
                                    @if ($usage->amount_used)
                                        | {{ __('Consumed') }}: {{ $qty($usage->amount_used) }} {{ $pu->unit_of_measure ?? __('units') }}@if ($line->unit_price) × {{ $formatPdfMoney($line->unit_price, $line->currency) }}@endif
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="num"><div>{{ $qty($line->quantity) }}</div></td>
                        <td class="num"><div>{{ $formatPdfMoney($line->unit_price, $line->currency) }}</div></td>
                        <td class="num">
                            <div class="amt">
                                @if ($line->resolvedChargeState() === 'free')
                                    {{ __('Free') }}
                                @elseif ($line->resolvedChargeState() === 'waived')
                                    {{ __('Waived') }}
                                @else
                                    {{ $formatPdfMoney($line->amount, $line->currency) }}
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="muted center" style="padding: 18px;">{{ __('No line items.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals --}}
        <table style="margin-top: 16px;">
            <tr>
                <td style="vertical-align: top;">&nbsp;</td>
                <td style="width: 280px; vertical-align: top;">
                    <table class="totals-tbl">
                        @if ($invoice->usd_khr_rate > 0)
                            <tr>
                                <td class="k">{{ __('USD charges') }}</td>
                                <td class="v">{{ $formatPdfMoney($invoice->native_usd_total, 'USD') }}</td>
                            </tr>
                            <tr>
                                <td class="k">{{ __('KHR charges') }}</td>
                                <td class="v">{{ $formatPdfMoney($invoice->native_khr_total, 'KHR') }}</td>
                            </tr>
                            @php
                                $reportingCurrency = Money::normalize($invoice->property?->settings?->currency ?? 'USD');
                                $primaryTotal = $reportingCurrency === 'KHR' ? $invoice->total_khr : $invoice->total_usd;
                                $primaryCurrency = $reportingCurrency;
                                $equivTotal = $reportingCurrency === 'KHR' ? $invoice->total_usd : $invoice->total_khr;
                                $equivCurrency = $reportingCurrency === 'KHR' ? 'USD' : 'KHR';
                            @endphp
                            <tr class="grand">
                                <td class="k">{{ __('Total') }}</td>
                                <td class="v">{{ $formatPdfMoney($primaryTotal, $primaryCurrency) }}</td>
                            </tr>
                            <tr>
                                <td class="k">{{ __('Equivalent') }}</td>
                                <td class="v">{{ $formatPdfMoney($equivTotal, $equivCurrency) }}</td>
                            </tr>
                            <tr>
                                <td class="k">{{ __('Exchange rate') }}</td>
                                <td class="v">1 USD = {{ number_format($invoice->usd_khr_rate, 0) }} KHR</td>
                            </tr>
                            @if ($invoice->exchange_rate_source)
                                <tr>
                                    <td class="k" style="font-size: 8px;">{{ __('Rate source') }}</td>
                                    <td class="v" style="font-size: 8px; font-weight: normal; color: #64748b;">
                                        {{ __($invoice->exchange_rate_source) }}@if ($invoice->exchange_rate_date) ({{ $date($invoice->exchange_rate_date) }})@endif
                                    </td>
                                </tr>
                            @endif
                            <tr style="border-top: 1px solid #e2e8f0;">
                                <td class="k">{{ __('Paid') }}</td>
                                <td class="v">
                                    {{ $formatPdfMoney($invoice->paid_usd, 'USD') }} / {{ $formatPdfMoney($invoice->paid_khr, 'KHR') }}
                                </td>
                            </tr>
                            <tr class="balance {{ $owing ? 'owing' : '' }}">
                                <td class="k">{{ __('Balance') }}</td>
                                <td class="v">
                                    {{ $formatPdfMoney($invoice->balance_usd, 'USD') }} / {{ $formatPdfMoney($invoice->balance_khr, 'KHR') }}
                                </td>
                            </tr>
                        @else
                            {{-- Legacy/Single Currency fallback --}}
                            <tr>
                                <td class="k">{{ __('Subtotal') }}</td>
                                <td class="v">{{ $formatPdfMoney($subtotal, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                            </tr>
                            <tr class="grand">
                                <td class="k">{{ __('Total due') }}</td>
                                <td class="v">{{ $formatPdfMoney($invoice->amount_due, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                            </tr>
                            <tr>
                                <td class="k">{{ __('Paid') }}</td>
                                <td class="v">{{ $formatPdfMoney($invoice->amount_paid, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                            </tr>
                            <tr class="balance {{ $owing ? 'owing' : '' }}">
                                <td class="k">{{ __('Balance') }}</td>
                                <td class="v">{{ $formatPdfMoney($invoice->balance, $invoice->property?->settings?->currency ?? 'USD') }}</td>
                            </tr>
                            <tr>
                                <td colspan="2" class="muted right" style="font-size: 8px; padding-top: 4px;">
                                    {{ __('Exchange-rate snapshot unavailable') }}
                                </td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        {{-- Payments --}}
        @if ($payments->isNotEmpty())
            <table class="pay-tbl">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Method') }}</th>
                        <th>{{ __('Receipt/Reference') }}</th>
                        <th class="num">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ $date($payment->paid_at) }}</td>
                            <td>{{ optional($payment->method)->getLabel() ?? '—' }}</td>
                            <td>{{ $payment->receipt_number ?? $payment->transaction_ref ?? '—' }}</td>
                            <td class="num">{{ $formatPdfMoney($payment->amount, $payment->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Notes --}}
        @if ($invoice->notes)
            <div class="notes-box">
                <div class="section-title">{{ __('Notes') }}</div>
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
