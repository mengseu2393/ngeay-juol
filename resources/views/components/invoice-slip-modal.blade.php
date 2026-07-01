{{--
    On-screen invoice document shown in the "View invoice" modal.

    Rendered as a self-contained, always-light "paper" surface: every colour is
    stated explicitly (Filament/Tailwind utility shades are NOT relied on here —
    several non-existent ones, e.g. emerald-450, silently rendered as no colour
    and caused the old contrast problems). The layout mirrors the printed PDF
    (resources/views/invoices/pdf.blade.php) so the preview is WYSIWYG. Printing
    and PDF both go through the server-rendered dompdf document, which is why
    there is no window.print() popup here.
--}}
@php
    /** @var \App\Models\Invoice $invoice */
    use App\Models\Invoice;

    $money = fn ($v) => '$' . number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    $subtotal = $invoice->lines->sum(fn ($l) => (float) $l->amount);
    $tenantName = $invoice->tenant?->name ?? $invoice->rental?->occupant_name ?? '—';
    $roomNumber = $invoice->rental?->unit?->room_number;
    $property = $invoice->property ?? $invoice->rental?->unit?->property;
    $business = $property?->name ?? config('app.name');
    $address = $property?->formatted_address;

    $initials = collect(preg_split('/\s+/', trim((string) $business)))
        ->filter()
        ->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->implode('') ?: 'R';

    $owing = (float) $invoice->balance > 0.005;

    // Status pill palette, keyed by the enum's Filament colour name.
    $palette = [
        'success' => ['bg' => '#d1fae5', 'fg' => '#065f46', 'bd' => '#a7f3d0'],
        'warning' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fde68a'],
        'info'    => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'bd' => '#bfdbfe'],
        'danger'  => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'bd' => '#fecaca'],
        'gray'    => ['bg' => '#f1f5f9', 'fg' => '#475569', 'bd' => '#e2e8f0'],
    ];
    $badge = $palette[$invoice->payment_status->getColor()] ?? $palette['gray'];
@endphp

<div class="rw-invoice-wrap">
    <style>
        /* Themeable tokens — light values here, overridden for dark mode below.
           The invoice adapts to the app theme; the printed PDF stays on white paper. */
        .rw-invoice-wrap {
            --rw-surface: #ffffff;
            --rw-surface-2: #f8fafc;
            --rw-ink: #0f172a;
            --rw-ink-soft: #334155;
            --rw-muted: #64748b;
            --rw-faint: #94a3b8;
            --rw-line: #e2e8f0;
            --rw-line-soft: #f1f5f9;
            --rw-emerald: #059669;
            --rw-emerald-ink: #065f46;
            --rw-emerald-tint: #ecfdf5;
            --rw-emerald-tint-bd: #a7f3d0;
            --rw-red-ink: #991b1b;
            --rw-red-tint: #fef2f2;
            --rw-red-tint-bd: #fecaca;
            --rw-tag-bg: #f1f5f9;
            --rw-shadow: 0 1px 2px -1px rgba(0,0,0,.06), 0 12px 32px -12px rgba(0,0,0,.18);
        }
        .fi.dark .rw-invoice-wrap {
            --rw-surface: #131c2e;
            --rw-surface-2: #1b2740;
            --rw-ink: #f1f5f9;
            --rw-ink-soft: #cbd5e1;
            --rw-muted: #94a3b8;
            --rw-faint: #64748b;
            --rw-line: #334155;
            --rw-line-soft: #26334d;
            --rw-emerald: #34d399;
            --rw-emerald-ink: #6ee7b7;
            --rw-emerald-tint: rgba(16,185,129,.13);
            --rw-emerald-tint-bd: rgba(16,185,129,.38);
            --rw-red-ink: #fca5a5;
            --rw-red-tint: rgba(239,68,68,.13);
            --rw-red-tint-bd: rgba(239,68,68,.38);
            --rw-tag-bg: rgba(148,163,184,.16);
            --rw-shadow: 0 1px 2px -1px rgba(0,0,0,.4), 0 14px 34px -14px rgba(0,0,0,.6);
        }
        .rw-invoice-wrap * { box-sizing: border-box; }

        .rw-invoice-toolbar { display: flex; justify-content: flex-end; gap: .5rem; margin-bottom: 1rem; }
        .rw-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem; border-radius: .625rem; font-size: .8rem; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: all .18s cubic-bezier(.4,0,.2,1); }
        .rw-btn svg { width: 1rem; height: 1rem; }
        /* Primary button keeps a fixed emerald fill + white text so it reads in both themes. */
        .rw-btn--primary { background: #059669; color: #fff; box-shadow: 0 2px 8px -2px rgba(5,150,105,.5); }
        .rw-btn--primary:hover { background: #047857; }
        .rw-btn--ghost { background: transparent; color: var(--rw-emerald); border-color: var(--rw-emerald-tint-bd); }
        .rw-btn--ghost:hover { background: var(--rw-emerald-tint); }

        .rw-invoice { background: var(--rw-surface); color: var(--rw-ink); border: 1px solid var(--rw-line); border-radius: 1rem; padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: var(--rw-shadow); font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; font-size: 13px; line-height: 1.5; }

        /* Header */
        .rw-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5rem; flex-wrap: wrap; padding-bottom: 1.5rem; border-bottom: 1px solid var(--rw-line); }
        .rw-brand { display: flex; align-items: center; gap: .85rem; min-width: 0; }
        .rw-logo { flex: none; width: 2.75rem; height: 2.75rem; border-radius: .75rem; background: linear-gradient(135deg, #059669, #10b981); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; }
        .rw-biz-name { font-size: 1.15rem; font-weight: 800; letter-spacing: -.01em; color: var(--rw-ink); }
        .rw-biz-addr { display: flex; align-items: flex-start; gap: .3rem; font-size: .75rem; color: var(--rw-muted); margin-top: .2rem; max-width: 18rem; line-height: 1.4; }
        .rw-biz-addr svg { width: .85rem; height: .85rem; margin-top: .1rem; flex: none; color: var(--rw-emerald); }
        .rw-doc { text-align: right; flex: none; }
        .rw-doc-label { font-size: .7rem; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; color: var(--rw-faint); }
        .rw-doc-no { font-size: 1rem; font-weight: 800; color: var(--rw-ink); margin-top: .15rem; }
        .rw-badge { display: inline-block; margin-top: .55rem; padding: .25rem .7rem; border-radius: 9999px; font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }

        /* Meta band */
        .rw-meta { display: flex; justify-content: space-between; gap: 2rem 3rem; flex-wrap: wrap; padding: 1.5rem 0; border-bottom: 1px solid var(--rw-line); }
        .rw-meta-col { min-width: 0; }
        .rw-label { font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--rw-emerald); margin-bottom: .45rem; }
        .rw-billto-name { font-size: 1rem; font-weight: 700; color: var(--rw-ink); }
        .rw-room { display: inline-block; margin-top: .4rem; padding: .12rem .55rem; border-radius: .4rem; background: var(--rw-emerald-tint); color: var(--rw-emerald-ink); font-size: .7rem; font-weight: 700; }
        .rw-phone { display: flex; align-items: center; gap: .3rem; margin-top: .45rem; font-size: .75rem; color: var(--rw-muted); }
        .rw-phone svg { width: .85rem; height: .85rem; color: var(--rw-faint); }
        .rw-defs { display: grid; grid-template-columns: auto auto; gap: .4rem 1.5rem; align-items: baseline; }
        .rw-defs dt { font-size: .72rem; color: var(--rw-muted); }
        .rw-defs dd { margin: 0; font-size: .8rem; font-weight: 600; color: var(--rw-ink); text-align: right; font-variant-numeric: tabular-nums; }
        .rw-defs dd.due { color: var(--rw-emerald); font-weight: 700; }

        /* Line items */
        .rw-items { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        .rw-items thead th { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--rw-muted); padding: 0 .75rem .55rem; border-bottom: 2px solid var(--rw-emerald); text-align: left; }
        .rw-items thead th.num { text-align: right; }
        .rw-items tbody td { padding: .8rem .75rem; border-bottom: 1px solid var(--rw-line-soft); font-size: .8125rem; vertical-align: top; }
        .rw-items tbody tr:last-child td { border-bottom: 0; }
        .rw-items td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .rw-items .desc { font-weight: 700; color: var(--rw-ink); }
        .rw-items .amount { font-weight: 700; color: var(--rw-ink); }
        .rw-items tr.waived .desc, .rw-items tr.waived .amount { color: var(--rw-faint); }
        .rw-tags { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .25rem; }
        .rw-tag { font-size: .68rem; font-weight: 600; color: var(--rw-faint); }
        .rw-tag--waived { text-transform: uppercase; letter-spacing: .04em; font-weight: 700; color: var(--rw-faint); background: var(--rw-tag-bg); padding: .05rem .35rem; border-radius: .3rem; }
        .rw-usage { margin-top: .45rem; padding: .45rem .6rem; background: var(--rw-surface-2); border: 1px solid var(--rw-line-soft); border-radius: .5rem; font-size: .72rem; color: var(--rw-muted); line-height: 1.45; }
        .rw-usage b { color: var(--rw-ink-soft); font-weight: 700; }
        .rw-empty { text-align: center; color: var(--rw-faint); padding: 1.5rem; font-size: .8rem; }

        /* Totals */
        .rw-totals { width: min(20rem, 100%); margin-left: auto; margin-top: 1.5rem; }
        .rw-total-row { display: flex; justify-content: space-between; align-items: baseline; padding: .4rem 0; font-size: .8125rem; }
        .rw-total-row .k { color: var(--rw-muted); }
        .rw-total-row .v { font-weight: 600; color: var(--rw-ink); font-variant-numeric: tabular-nums; }
        .rw-total-row.grand { border-top: 1px solid var(--rw-line); margin-top: .3rem; padding-top: .65rem; }
        .rw-total-row.grand .k { font-weight: 700; color: var(--rw-ink); font-size: .9rem; }
        .rw-total-row.grand .v { font-weight: 800; color: var(--rw-emerald); font-size: .95rem; }
        .rw-balance { display: flex; justify-content: space-between; align-items: baseline; margin-top: .65rem; padding: .7rem .9rem; border-radius: .65rem; background: var(--rw-emerald-tint); border: 1px solid var(--rw-emerald-tint-bd); }
        .rw-balance .k { font-weight: 800; color: var(--rw-emerald-ink); font-size: .85rem; }
        .rw-balance .v { font-weight: 800; color: var(--rw-emerald-ink); font-size: 1.05rem; font-variant-numeric: tabular-nums; }
        .rw-balance.owing { background: var(--rw-red-tint); border-color: var(--rw-red-tint-bd); }
        .rw-balance.owing .k, .rw-balance.owing .v { color: var(--rw-red-ink); }

        /* Notes */
        .rw-notes { margin-top: 1.75rem; padding: .8rem 1rem; border: 1px solid var(--rw-line-soft); border-left: 3px solid var(--rw-emerald); background: var(--rw-surface-2); border-radius: .5rem; }
        .rw-notes .rw-label { margin-bottom: .3rem; }
        .rw-notes p { margin: 0; font-size: .8rem; color: var(--rw-ink-soft); line-height: 1.5; }
    </style>

    {{-- Toolbar (screen only — printing/PDF is server-rendered, so no browser headers) --}}
    <div class="rw-invoice-toolbar">
        <a href="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4', 'mode' => 'stream']) }}"
           target="_blank" rel="noopener" class="rw-btn rw-btn--primary">
            <x-filament::icon icon="heroicon-m-printer" />
            {{ __('Print') }}
        </a>
        <a href="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4']) }}"
           target="_blank" rel="noopener" class="rw-btn rw-btn--ghost">
            <x-filament::icon icon="heroicon-m-arrow-down-tray" />
            {{ __('PDF') }}
        </a>
    </div>

    <div class="rw-invoice">
        {{-- Header --}}
        <div class="rw-head">
            <div class="rw-brand">
                <div class="rw-logo">{{ $initials }}</div>
                <div>
                    <div class="rw-biz-name">{{ $business }}</div>
                    @if ($address)
                        <div class="rw-biz-addr">
                            <x-filament::icon icon="heroicon-o-map-pin" />
                            <span>{{ $address }}</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="rw-doc">
                <div class="rw-doc-label">{{ __('Invoice') }}</div>
                <div class="rw-doc-no">{{ $invoice->invoice_number }}</div>
                <span class="rw-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }}; border: 1px solid {{ $badge['bd'] }};">
                    {{ $invoice->payment_status->getLabel() }}
                </span>
            </div>
        </div>

        {{-- Bill-to + billing meta --}}
        <div class="rw-meta">
            <div class="rw-meta-col">
                <div class="rw-label">{{ __('Bill to') }}</div>
                <div class="rw-billto-name">{{ $tenantName }}</div>
                @if ($roomNumber)
                    <div><span class="rw-room">{{ __('Room') }} {{ $roomNumber }}</span></div>
                @endif
                @if ($invoice->tenant?->phone_number)
                    <div class="rw-phone">
                        <x-filament::icon icon="heroicon-o-phone" />
                        <span>{{ $invoice->tenant->phone_number }}</span>
                    </div>
                @endif
            </div>
            <div class="rw-meta-col">
                <dl class="rw-defs">
                    <dt>{{ __('Billing Period') }}</dt>
                    <dd>{{ $invoice->billingPeriodLabel() }}</dd>
                    <dt>{{ __('Issued Date') }}</dt>
                    <dd>{{ Invoice::displayDate($invoice->issue_date) }}</dd>
                    <dt>{{ __('Due Date') }}</dt>
                    <dd class="due">{{ Invoice::displayDate($invoice->due_date) }}</dd>
                </dl>
            </div>
        </div>

        {{-- Line items --}}
        <table class="rw-items">
            <thead>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Qty') }}</th>
                    <th class="num">{{ __('Price') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->lines as $line)
                    @php $usage = $line->utilityUsage; @endphp
                    <tr class="{{ $line->is_waived ? 'waived' : '' }}">
                        <td>
                            <div class="desc">{{ $line->description }}</div>
                            @if ($line->line_type || $line->is_waived)
                                <div class="rw-tags">
                                    @if ($line->line_type)
                                        <span class="rw-tag">{{ $line->line_type->getLabel() }}</span>
                                    @endif
                                    @if ($line->is_waived)
                                        <span class="rw-tag--waived">{{ __('Waived') }}</span>
                                    @endif
                                </div>
                            @endif
                            @if ($usage && $usage->propertyUtility)
                                @php $pu = $usage->propertyUtility; @endphp
                                <div class="rw-usage">
                                    <b>{{ $pu->name }}</b>@if ($pu->provider) ({{ $pu->provider }})@endif
                                    @if ($usage->old_reading !== null && $usage->new_reading !== null)
                                        <br>
                                        {{ __('Meter') }}: {{ number_format((float) $usage->old_reading, 1) }} → {{ number_format((float) $usage->new_reading, 1) }}
                                        @if ($usage->amount_used)
                                            · {{ __('Consumed') }}: {{ $qty($usage->amount_used) }} {{ $pu->unit_of_measure ?? __('units') }}@if ($pu->rate) × {{ $money($pu->rate) }}@endif
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="num">{{ $qty($line->quantity) }}</td>
                        <td class="num">{{ $money($line->unit_price) }}</td>
                        <td class="num amount">{{ $line->is_waived ? $money(0) : $money($line->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="rw-empty">{{ __('No line items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals --}}
        <div class="rw-totals">
            <div class="rw-total-row">
                <span class="k">{{ __('Subtotal') }}</span>
                <span class="v">{{ $money($subtotal) }}</span>
            </div>
            <div class="rw-total-row grand">
                <span class="k">{{ __('Total Due') }}</span>
                <span class="v">{{ $money($invoice->amount_due) }}</span>
            </div>
            <div class="rw-total-row">
                <span class="k">{{ __('Paid') }}</span>
                <span class="v">{{ $money($invoice->amount_paid) }}</span>
            </div>
            <div class="rw-balance {{ $owing ? 'owing' : '' }}">
                <span class="k">{{ __('Balance') }}</span>
                <span class="v">{{ $money($invoice->balance) }}</span>
            </div>
        </div>

        {{-- Notes --}}
        @if ($invoice->notes)
            <div class="rw-notes">
                <div class="rw-label">{{ __('Notes') }}</div>
                <p>{{ $invoice->notes }}</p>
            </div>
        @endif
    </div>
</div>
