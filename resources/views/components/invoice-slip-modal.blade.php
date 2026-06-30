@php
    $money = fn ($v) => '$' . number_format((float) $v, 2);
    $date = fn ($d) => $d ? $d->format('d M Y') : '—';
    $subtotal = $invoice->lines->sum(fn ($l) => (float) $l->amount);
    $tenantName = $invoice->tenant?->name ?? $invoice->rental?->occupant_name ?? '—';
    $roomNumber = $invoice->rental?->unit?->room_number;
    $property = $invoice->property ?? $invoice->rental?->unit?->property;
    $balanceColor = $invoice->balance > 0 ? 'danger' : 'success';
@endphp

<div class="invoice-slip max-w-2xl mx-auto">

    {{-- Print / Download toolbar --}}
    <div class="invoice-slip-toolbar flex items-center justify-end gap-2 mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
        <button type="button"
           onclick="printInvoiceSlip(event)"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition cursor-pointer">
            <x-filament::icon icon="heroicon-m-printer" class="w-4 h-4" />
            {{ __('Print') }}
        </button>
        <a href="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4']) }}"
           target="_blank"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition">
            <x-filament::icon icon="heroicon-m-arrow-down-tray" class="w-4 h-4" />
            {{ __('PDF') }}
        </a>
    </div>

    {{-- Print-only styles injected into the popup window --}}
    <template id="print-styles">
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
                margin: 0;
                padding: 24px 32px;
                color: #111827;
                font-size: 12px;
                line-height: 1.5;
            }
            table { border-collapse: collapse; width: 100%; }
            .right { text-align: right; }
            .muted { color: #6b7280; }
            .bold { font-weight: 700; }

            .header-tbl td { vertical-align: top; }
            .biz { font-size: 16px; font-weight: 700; color: #111827; }
            .biz-addr { font-size: 11px; color: #6b7280; margin-top: 2px; max-width: 280px; }
            .doc-label { font-size: 18px; font-weight: 700; color: #111827; letter-spacing: 0.5px; }
            .doc-no { font-size: 12px; color: #374151; margin-top: 1px; }
            .status-badge {
                display: inline-block;
                margin-top: 4px;
                padding: 2px 8px;
                border-radius: 9999px;
                font-size: 10px;
                font-weight: 600;
            }
            .section-title { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
            .billto { font-size: 12px; font-weight: 700; color: #111827; }
            .billto-sub { font-size: 11px; color: #4b5563; margin-top: 1px; }

            .meta-tbl { margin-top: 14px; border: 1px solid #e5e7eb; width: 100%; }
            .meta-tbl td { padding: 6px 10px; font-size: 11px; border-right: 1px solid #e5e7eb; width: 33%; vertical-align: top; }
            .meta-tbl td:last-child { border-right: 0; }
            .meta-tbl .k { color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 1px; }

            .items-tbl { margin-top: 14px; }
            .items-tbl th {
                background: #111827; color: #fff; font-size: 9px; font-weight: 700;
                text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 8px; text-align: left;
            }
            .items-tbl th.num, .items-tbl td.num { text-align: right; }
            .items-tbl td {
                padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px; vertical-align: top;
            }
            .items-tbl tr.waived td { color: #9ca3af; }
            .waived-tag { font-style: italic; color: #9ca3af; font-size: 9px; }
            .usage-detail { font-size: 9px; color: #6b7280; margin-top: 1px; line-height: 1.4; }

            .totals-tbl { margin-top: 10px; }
            .totals-tbl td { padding: 4px 8px; font-size: 11px; }
            .totals-tbl td.k { text-align: right; color: #4b5563; }
            .totals-tbl td.v { text-align: right; width: 120px; }
            .totals-tbl tr.grand td { font-size: 13px; font-weight: 700; color: #111827; border-top: 2px solid #111827; }
            .totals-tbl tr.balance td { font-weight: 700; }

            .notes-box { margin-top: 16px; border: 1px solid #e5e7eb; background: #f9fafb; padding: 8px 10px; font-size: 11px; color: #374151; }
            .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
        </style>
    </template>

    {{-- Header --}}
    <table class="w-full">
        <tr>
            <td class="align-top">
                <div class="text-lg font-bold text-gray-900 dark:text-white">
                    {{ $property?->name ?? config('app.name') }}
                </div>
                @if ($property)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        @php
                            $parts = array_filter([$property->address_line, $property->street, $property->city]);
                        @endphp
                        {{ implode(', ', $parts) ?: '—' }}
                    </p>
                @endif
            </td>
            <td class="align-top text-right">
                <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide">
                    {{ __('Invoice') }}
                </div>
                <div class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">
                    {{ $invoice->invoice_number }}
                </div>
                <x-filament::badge :color="$invoice->payment_status->getColor()" class="mt-2">
                    {{ $invoice->payment_status->getLabel() }}
                </x-filament::badge>
            </td>
        </tr>
    </table>

    {{-- Bill To + Meta --}}
    <table class="w-full" style="margin-top: 16px;">
        <tr>
            <td class="align-top w-1/2">
                <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">
                    {{ __('Bill to') }}
                </div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ $tenantName }}
                </div>
                @if ($roomNumber)
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Room') }} {{ $roomNumber }}
                    </div>
                @endif
                @if ($invoice->tenant?->phone_number)
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $invoice->tenant->phone_number }}
                    </div>
                @endif
            </td>
            <td class="align-top w-1/2">
                <table class="w-full text-sm">
                    <tr>
                        <td class="text-gray-400 dark:text-gray-500 py-0.5">{{ __('Period') }}</td>
                        <td class="text-right text-gray-700 dark:text-gray-300 font-medium py-0.5">
                            {{ $date($invoice->period_start) }} – {{ $date($invoice->period_end) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="text-gray-400 dark:text-gray-500 py-0.5">{{ __('Issued') }}</td>
                        <td class="text-right text-gray-700 dark:text-gray-300 font-medium py-0.5">{{ $date($invoice->issue_date) }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-400 dark:text-gray-500 py-0.5">{{ __('Due') }}</td>
                        <td class="text-right text-gray-700 dark:text-gray-300 font-medium py-0.5">{{ $date($invoice->due_date) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Divider --}}
    <div class="border-t border-gray-200 dark:border-gray-700 mt-5"></div>

    {{-- Line Items Table --}}
    <div class="mt-5">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left pb-2 font-semibold">{{ __('Description') }}</th>
                    <th class="text-right pb-2 font-semibold">{{ __('Qty') }}</th>
                    <th class="text-right pb-2 font-semibold">{{ __('Price') }}</th>
                    <th class="text-right pb-2 font-semibold">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->lines as $line)
                    @php $usage = $line->utilityUsage; @endphp
                    <tr class="border-b border-gray-100 dark:border-gray-800 {{ $line->is_waived ? 'opacity-50' : '' }}">
                        <td class="py-2.5 pr-4">
                            <div class="font-medium text-gray-900 dark:text-white">
                                {{ $line->description }}
                            </div>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                @if ($line->line_type)
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $line->line_type->getLabel() }}
                                    </span>
                                @endif
                                @if ($line->is_waived)
                                    <x-filament::badge size="sm" color="gray">
                                        {{ __('Waived') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            {{-- Utility usage details --}}
                            @if ($usage && $usage->propertyUtility)
                                @php $pu = $usage->propertyUtility; @endphp
                                <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
                                    <div class="font-medium text-gray-700 dark:text-gray-300">
                                        {{ $pu->name }}
                                        @if ($pu->provider)
                                            · {{ $pu->provider }}
                                        @endif
                                    </div>
                                    @if ($usage->reading_type)
                                        <div>
                                            {{ $usage->reading_type->getLabel() }}
                                            @if ($usage->reading_date)
                                                · {{ $date($usage->reading_date) }}
                                            @endif
                                        </div>
                                    @endif
                                    @if ($usage->old_reading !== null && $usage->new_reading !== null)
                                        <div>
                                            {{ __('Meter') }}:
                                            {{ number_format((float) $usage->old_reading, 1) }}
                                            → {{ number_format((float) $usage->new_reading, 1) }}
                                        </div>
                                        @if ($usage->amount_used)
                                            <div>
                                                {{ __('Consumed') }}:
                                                {{ rtrim(rtrim(number_format((float) $usage->amount_used, 3), '0'), '.') }}
                                                {{ $pu->unit_of_measure ?? __('units') }}
                                                @if ($pu->rate)
                                                    × {{ $money($pu->rate) }}/{{ $pu->unit_of_measure ?? __('unit') }}
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                    @if ($pu->billing_type)
                                        <div class="italic">
                                            {{ $pu->billing_type->getLabel() }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-right py-2.5 px-2 text-gray-600 dark:text-gray-400 whitespace-nowrap align-top">
                            {{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}
                        </td>
                        <td class="text-right py-2.5 px-2 text-gray-600 dark:text-gray-400 whitespace-nowrap align-top">
                            {{ $money($line->unit_price) }}
                        </td>
                        <td class="text-right py-2.5 pl-4 whitespace-nowrap font-medium text-gray-900 dark:text-white align-top">
                            {{ $line->is_waived ? $money(0) : $money($line->amount) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-4 text-center text-gray-400 dark:text-gray-500">
                            {{ __('No line items.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Totals --}}
    <div class="mt-4 flex justify-end">
        <table class="w-64 text-sm">
            <tr>
                <td class="py-1 text-gray-500 dark:text-gray-400">{{ __('Subtotal') }}</td>
                <td class="py-1 text-right text-gray-900 dark:text-white font-medium">{{ $money($subtotal) }}</td>
            </tr>
            <tr class="border-t border-gray-200 dark:border-gray-700">
                <td class="py-2 text-gray-900 dark:text-white font-bold">{{ __('Total due') }}</td>
                <td class="py-2 text-right text-gray-900 dark:text-white font-bold text-base">
                    {{ $money($invoice->amount_due) }}
                </td>
            </tr>
            <tr>
                <td class="py-1 text-gray-500 dark:text-gray-400">{{ __('Paid') }}</td>
                <td class="py-1 text-right text-success-600 dark:text-success-400 font-medium">
                    {{ $money($invoice->amount_paid) }}
                </td>
            </tr>
            <tr class="border-t border-gray-200 dark:border-gray-700">
                <td class="py-2 text-gray-900 dark:text-white font-bold">{{ __('Balance') }}</td>
                <td class="py-2 text-right text-{{ $balanceColor }}-600 dark:text-{{ $balanceColor }}-400 font-bold">
                    {{ $money($invoice->balance) }}
                </td>
            </tr>
        </table>
    </div>

    {{-- Notes --}}
    @if ($invoice->notes)
        <div class="border-t border-gray-200 dark:border-gray-700 mt-5 pt-5">
            <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-2">
                {{ __('Notes') }}
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3">
                {{ $invoice->notes }}
            </div>
        </div>
    @endif
</div>


