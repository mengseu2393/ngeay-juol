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
    use App\Support\BrandLogo;
    use App\Support\Money;

    $money = fn ($v) => Money::formatForRecord($v, $invoice);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    $visibleLines = $invoice->lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice());
    $subtotal = $visibleLines->sum(fn ($l) => (float) $l->amount);
    $tenantName = $invoice->tenant?->name ?? $invoice->rental?->occupant_name ?? '—';
    $roomNumber = $invoice->rental?->unit?->room_number;
    $property = $invoice->property ?? $invoice->rental?->unit?->property;
    $business = $property?->name ?? config('app.name');
    $address = $property?->formatted_address;

    $logoDataUri = BrandLogo::dataUri();
    $initials = BrandLogo::fallbackInitials((string) $business);

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
        .rw-logo { flex: none; width: 2.75rem; height: 2.75rem; border-radius: .75rem; background: #fff; border: 1px solid var(--rw-line); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .rw-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .rw-logo span { color: #059669; font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; }
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

        @media print {
            body.print-58mm-active {
                background: #fff !important;
                color: #000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            body.print-58mm-active * {
                visibility: hidden !important;
            }
            body.print-58mm-active .rw-invoice-wrap,
            body.print-58mm-active .rw-invoice-wrap * {
                visibility: visible !important;
            }
            body.print-58mm-active .rw-invoice-wrap {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 58mm !important;
                max-width: 58mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            body.print-58mm-active .rw-invoice-toolbar {
                display: none !important;
            }
            @page {
                size: 58mm auto;
                margin: 0;
            }
            body.print-58mm-active .rw-invoice {
                width: 58mm !important;
                max-width: 58mm !important;
                box-sizing: border-box !important;
                padding: 3mm 3mm !important;
                margin: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: #fff !important;
                color: #000 !important;
                font-family: 'Arial Black', 'Trebuchet MS', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                font-size: 10pt !important;
                font-weight: 900 !important;
                line-height: 1.25 !important;
                border-radius: 0 !important;
                -webkit-text-stroke: 0.4px #000 !important;
            }
            body.print-58mm-active .rw-head {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                gap: 3px !important;
                padding-bottom: 6px !important;
                border-bottom: 2px dashed #000 !important;
            }
            body.print-58mm-active .rw-brand {
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                gap: 2px !important;
                width: 100% !important;
            }
            body.print-58mm-active .rw-logo {
                width: 32px !important;
                height: 32px !important;
                margin: 0 auto !important;
                border-color: #000 !important;
            }
            body.print-58mm-active .rw-biz-name {
                font-size: 13pt !important;
                font-weight: 900 !important;
                text-align: center !important;
                color: #000 !important;
                -webkit-text-stroke: 0.75px #000 !important;
            }
            body.print-58mm-active .rw-biz-addr {
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                text-align: center !important;
                justify-content: center !important;
                color: #000 !important;
                max-width: 100% !important;
                margin-top: 1px !important;
                line-height: 1.2 !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
            body.print-58mm-active .rw-biz-addr svg {
                display: none !important;
            }
            body.print-58mm-active .rw-doc {
                text-align: center !important;
                margin-top: 2px !important;
                width: 100% !important;
            }
            body.print-58mm-active .rw-doc-label {
                font-size: 9.5pt !important;
                font-weight: 900 !important;
                letter-spacing: 0.05em !important;
                color: #000 !important;
                -webkit-text-stroke: 0.5px #000 !important;
            }
            body.print-58mm-active .rw-doc-no {
                font-size: 11pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                -webkit-text-stroke: 0.6px #000 !important;
            }
            body.print-58mm-active .rw-badge {
                display: inline-block !important;
                border: 2px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                padding: 0px 4px !important;
                margin-top: 2px !important;
                border-radius: 2px !important;
                -webkit-text-stroke: 0.4px #000 !important;
            }
            body.print-58mm-active .rw-meta {
                display: flex !important;
                flex-direction: column !important;
                gap: 4px !important;
                padding: 6px 0 !important;
                border-bottom: 2px dashed #000 !important;
            }
            body.print-58mm-active .rw-label {
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                letter-spacing: 0.05em !important;
                color: #000 !important;
                margin-bottom: 1px !important;
                -webkit-text-stroke: 0.45px #000 !important;
            }
            body.print-58mm-active .rw-billto-name {
                font-size: 11pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                -webkit-text-stroke: 0.6px #000 !important;
            }
            body.print-58mm-active .rw-room {
                display: inline-block !important;
                font-size: 9pt !important;
                font-weight: 900 !important;
                border: 2px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 0px 4px !important;
                margin-top: 1px !important;
                border-radius: 2px !important;
                -webkit-text-stroke: 0.45px #000 !important;
            }
            body.print-58mm-active .rw-phone {
                font-size: 9pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                margin-top: 1px !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
            body.print-58mm-active .rw-phone svg {
                display: none !important;
            }
            body.print-58mm-active .rw-defs {
                display: block !important;
                margin-top: 2px !important;
                width: 100% !important;
            }
            body.print-58mm-active .rw-defs dt {
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                float: left !important;
                clear: left !important;
                padding: 1px 0 !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
            body.print-58mm-active .rw-defs dd {
                font-size: 9pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                float: right !important;
                clear: right !important;
                text-align: right !important;
                padding: 1px 0 !important;
                -webkit-text-stroke: 0.5px #000 !important;
            }
            body.print-58mm-active .rw-items {
                display: block !important;
                width: 100% !important;
                margin-top: 6px !important;
                border-collapse: collapse !important;
            }
            body.print-58mm-active .rw-items thead {
                display: block !important;
                border-bottom: 2px solid #000 !important;
            }
            body.print-58mm-active .rw-items thead tr {
                display: flex !important;
                justify-content: space-between !important;
                width: 100% !important;
            }
            body.print-58mm-active .rw-items thead th {
                display: block !important;
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                padding: 2px 0 !important;
                border: none !important;
                color: #000 !important;
                -webkit-text-stroke: 0.45px #000 !important;
            }
            body.print-58mm-active .rw-items thead th.num {
                display: none !important;
            }
            body.print-58mm-active .rw-items tbody {
                display: block !important;
                width: 100% !important;
            }
            body.print-58mm-active .rw-items tbody tr {
                display: block !important;
                padding: 4px 0 !important;
                border-bottom: 1.5px dashed #000 !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
            body.print-58mm-active .rw-items tbody tr:last-child {
                border-bottom: none !important;
            }
            body.print-58mm-active .rw-items tbody td {
                display: block !important;
                padding: 0 !important;
                border: none !important;
                width: 100% !important;
                box-sizing: border-box !important;
                font-size: 9.5pt !important;
                font-weight: 900 !important;
                -webkit-text-stroke: 0.4px #000 !important;
            }
            body.print-58mm-active .rw-items tbody td.num {
                display: none !important;
            }
            body.print-58mm-active .rw-items .desc {
                font-size: 10pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
                -webkit-text-stroke: 0.6px #000 !important;
            }
            body.print-58mm-active .rw-58mm-subline {
                display: flex !important;
                justify-content: space-between !important;
                align-items: baseline !important;
                font-size: 9pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                margin-top: 2px !important;
                -webkit-text-stroke: 0.45px #000 !important;
            }
            body.print-58mm-active .rw-58mm-amount {
                font-weight: 900 !important;
                font-size: 9.5pt !important;
                color: #000 !important;
                -webkit-text-stroke: 0.5px #000 !important;
            }
            body.print-58mm-active .rw-tags {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 2px !important;
                margin-top: 2px !important;
            }
            body.print-58mm-active .rw-tag,
            body.print-58mm-active .rw-tag--waived {
                font-size: 8pt !important;
                font-weight: 900 !important;
                border: 1.5px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 0 2px !important;
                border-radius: 2px !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
            body.print-58mm-active .rw-usage {
                font-size: 8.5pt !important;
                font-weight: 900 !important;
                padding: 2px 0 !important;
                margin-top: 2px !important;
                background: transparent !important;
                border: none !important;
                color: #000 !important;
                line-height: 1.2 !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
            body.print-58mm-active .rw-totals {
                width: 100% !important;
                margin-top: 6px !important;
                border-top: 2px dashed #000 !important;
                padding-top: 4px !important;
            }
            body.print-58mm-active .rw-total-row {
                display: flex !important;
                justify-content: space-between !important;
                font-size: 9.5pt !important;
                font-weight: 900 !important;
                padding: 1px 0 !important;
                color: #000 !important;
                -webkit-text-stroke: 0.4px #000 !important;
            }
            body.print-58mm-active .rw-total-row .k,
            body.print-58mm-active .rw-total-row .v {
                color: #000 !important;
                font-weight: 900 !important;
                -webkit-text-stroke: 0.4px #000 !important;
            }
            body.print-58mm-active .rw-total-row.grand {
                border-top: 2px solid #000 !important;
                margin-top: 3px !important;
                padding-top: 3px !important;
            }
            body.print-58mm-active .rw-total-row.grand .k,
            body.print-58mm-active .rw-total-row.grand .v {
                font-size: 11pt !important;
                font-weight: 900 !important;
                color: #000 !important;
                -webkit-text-stroke: 0.65px #000 !important;
            }
            body.print-58mm-active .rw-balance {
                display: flex !important;
                justify-content: space-between !important;
                border: 2px solid #000 !important;
                background: #fff !important;
                padding: 3px 4px !important;
                border-radius: 0 !important;
                margin-top: 4px !important;
            }
            body.print-58mm-active .rw-balance .k,
            body.print-58mm-active .rw-balance .v {
                color: #000 !important;
                font-size: 10.5pt !important;
                font-weight: 900 !important;
                -webkit-text-stroke: 0.65px #000 !important;
            }
            body.print-58mm-active .rw-notes {
                margin-top: 6px !important;
                padding: 4px !important;
                border: 1.5px dashed #000 !important;
                background: #fff !important;
                font-size: 9pt !important;
                font-weight: 900 !important;
                border-left: 1.5px dashed #000 !important;
                color: #000 !important;
                -webkit-text-stroke: 0.35px #000 !important;
            }
        }
    </style>

    {{-- Toolbar (screen only — printing/PDF is server-rendered, so no browser headers).
         Print: desktop opens the inline PDF (browser print preview). Phones/PWA have no
         inline PDF viewer, so there we fetch the PDF and hand it to the system share
         sheet (navigator.share) — from which the user can print or send it (Telegram…). --}}
    <div class="rw-invoice-toolbar">
        <a href="{{ route('invoices.view', ['invoice' => $invoice]) }}"
           class="rw-btn rw-btn--ghost">
            {{ __('View details') }}
        </a>
        <button type="button" class="rw-btn rw-btn--primary"
                data-stream-url="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4', 'mode' => 'stream']) }}"
                data-download-url="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4']) }}"
                data-filename="{{ \App\Services\InvoicePdfService::filename($invoice, 'pdf') }}"
                data-preparing="{{ __('Preparing…') }}"
                onclick="rwPrintInvoice(this)">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                <path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v1.5A1.75 1.75 0 0 1 16.75 6H18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-.25v1.25a1.75 1.75 0 0 1-1.75 1.75h-8.5A1.75 1.75 0 0 1 4 17.25V16H3.75A2 2 0 0 1 1.75 14V8a2 2 0 0 1 2-2h1.25A1.75 1.75 0 0 1 6.75 4.25v-1.5ZM6.5 4.25c0-.138.112-.25.25-.25h6.5c.138 0 .25.112.25.25v1.5c0 .138-.112.25-.25.25h-6.5a.25.25 0 0 1-.25-.25v-1.5ZM5.5 17.25c0-.138.112-.25.25-.25h8.5c.138 0 .25.112.25.25v-3.5c0-.138-.112-.25-.25-.25h-8.5c-.138 0-.25.112-.25.25v3.5Z" clip-rule="evenodd" />
            </svg>
            <span data-label>{{ __('Print') }}</span>
        </button>
        <button type="button" class="rw-btn rw-btn--ghost"
                id="btn-print-58mm"
                data-stream-url="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => '58mm', 'mode' => 'stream']) }}"
                data-download-url="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => '58mm']) }}"
                data-filename="{{ \App\Services\InvoicePdfService::filename($invoice, 'pdf') }}"
                onclick="rwPrintInvoice58mm(this)">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                <path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v1.5A1.75 1.75 0 0 1 16.75 6H18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-.25v1.25a1.75 1.75 0 0 1-1.75 1.75h-8.5A1.75 1.75 0 0 1 4 17.25V16H3.75A2 2 0 0 1 1.75 14V8a2 2 0 0 1 2-2h1.25A1.75 1.75 0 0 1 6.75 4.25v-1.5ZM6.5 4.25c0-.138.112-.25.25-.25h6.5c.138 0 .25.112.25.25v1.5c0 .138-.112.25-.25.25h-6.5a.25.25 0 0 1-.25-.25v-1.5ZM5.5 17.25c0-.138.112-.25.25-.25h8.5c.138 0 .25.112.25.25v-3.5c0-.138-.112-.25-.25-.25h-8.5c-.138 0-.25.112-.25.25v3.5Z" clip-rule="evenodd" />
            </svg>
            <span data-label>{{ __('Print 58mm') }}</span>
        </button>
        {{-- window.rwPrintInvoice is loaded globally (components.rw-print-script):
             scripts inside this component would not execute when Livewire injects
             it into a Filament modal. --}}
        <a href="{{ route('invoices.pdf', ['invoice' => $invoice, 'size' => 'a4']) }}"
           target="_blank" rel="noopener" class="rw-btn rw-btn--ghost">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.275a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.089V2.75Z" />
                <path d="M16.4 13.25a1 1 0 0 0-.8-.4H4.4a1 1 0 0 0-.8.4 1.5 1.5 0 0 0-.4 1v.85c0 .69.56 1.25 1.25 1.25h11.1c.69 0 1.25-.56 1.25-1.25v-.85a1.5 1.5 0 0 0-.4-1Z" />
            </svg>
            {{ __('PDF') }}
        </a>
    </div>

    <div class="rw-invoice">
        {{-- Header --}}
        <div class="rw-head">
            <div class="rw-brand">
                <div class="rw-logo">
                    @if ($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="{{ $business }}">
                    @else
                        <span>{{ $initials }}</span>
                    @endif
                </div>
                <div>
                    <div class="rw-biz-name">{{ $business }}</div>
                    @if ($address)
                        <div class="rw-biz-addr">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
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
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-2.824-1.502-5.183-3.861-6.686-6.686l1.293-.97c.362-.272.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                        </svg>
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
                @forelse ($visibleLines as $line)
                    @php $usage = $line->utilityUsage; @endphp
                    <tr class="{{ $line->resolvedChargeState() === 'waived' ? 'waived' : '' }}">
                        <td>
                            <div class="desc">{{ $line->getTranslatedDescription() }}</div>
                            <div class="rw-58mm-subline" style="display: none;">
                                <span>{{ $qty($line->quantity) }} × {{ Money::format($line->unit_price, $line->currency) }}</span>
                                <span class="rw-58mm-amount">
                                    @if ($line->resolvedChargeState() === 'free')
                                        {{ __('Free') }}
                                    @elseif ($line->resolvedChargeState() === 'waived')
                                        {{ __('Waived') }}
                                    @else
                                        {{ Money::format($line->amount, $line->currency) }}
                                    @endif
                                </span>
                            </div>
                            @if ($line->line_type || $line->resolvedChargeState() !== 'normal')
                                <div class="rw-tags">
                                    @if ($line->line_type)
                                        <span class="rw-tag">{{ $line->line_type->getLabel() }}</span>
                                    @endif
                                    @if ($line->resolvedChargeState() === 'free')
                                        <span class="rw-tag--waived">{{ __('Free') }}</span>
                                    @elseif ($line->resolvedChargeState() === 'waived')
                                        <span class="rw-tag--waived">{{ __('Waived') }}</span>
                                    @elseif ($line->resolvedChargeState() === 'custom')
                                        <span class="rw-tag--waived">{{ __('Adjusted') }}</span>
                                    @endif
                                </div>
                            @endif
                            @if ($line->resolvedChargeState() === 'waived' && $line->resolvedChargeStateReason())
                                <div class="rw-usage">{{ $line->resolvedChargeStateReason() }}</div>
                            @endif
                            @if ($usage && $usage->propertyUtility)
                                @php $pu = $usage->propertyUtility; @endphp
                                <div class="rw-usage">
                                    <b>{{ __($pu->name) }}</b>@if ($pu->provider) ({{ $pu->provider }})@endif
                                    @if ($usage->old_reading !== null && $usage->new_reading !== null)
                                        <br>
                                        {{ __('Meter') }}: {{ number_format((float) $usage->old_reading, 1) }} → {{ number_format((float) $usage->new_reading, 1) }}
                                        @if ($usage->amount_used)
                                            · {{ __('Consumed') }}: {{ $qty($usage->amount_used) }} {{ $pu->unit_of_measure ?? __('units') }}@if ($line->unit_price) × {{ Money::format($line->unit_price, $line->currency) }}@endif
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="num">{{ $qty($line->quantity) }}</td>
                        <td class="num">{{ Money::format($line->unit_price, $line->currency) }}</td>
                        <td class="num amount">
                            @if ($line->resolvedChargeState() === 'free')
                                {{ __('Free') }}
                            @elseif ($line->resolvedChargeState() === 'waived')
                                {{ __('Waived') }}
                            @else
                                {{ Money::format($line->amount, $line->currency) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="rw-empty">{{ __('No line items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals --}}
        <div class="rw-totals">
            @if ($invoice->usd_khr_rate > 0)
                <div class="rw-total-row">
                    <span class="k">{{ __('USD charges') }}</span>
                    <span class="v">{{ Money::format($invoice->native_usd_total, 'USD') }}</span>
                </div>
                <div class="rw-total-row">
                    <span class="k">{{ __('KHR charges') }}</span>
                    <span class="v">{{ Money::format($invoice->native_khr_total, 'KHR') }}</span>
                </div>

                @php
                    $reportingCurrency = Money::normalize($invoice->property?->settings?->currency ?? 'USD');
                    $primaryTotal = $reportingCurrency === 'KHR' ? $invoice->total_khr : $invoice->total_usd;
                    $primaryCurrency = $reportingCurrency;
                    $equivTotal = $reportingCurrency === 'KHR' ? $invoice->total_usd : $invoice->total_khr;
                    $equivCurrency = $reportingCurrency === 'KHR' ? 'USD' : 'KHR';
                @endphp

                <div class="rw-total-row grand">
                    <span class="k">{{ __('Total') }}</span>
                    <span class="v">{{ Money::format($primaryTotal, $primaryCurrency) }}</span>
                </div>
                <div class="rw-total-row">
                    <span class="k">{{ __('Equivalent') }}</span>
                    <span class="v">{{ Money::format($equivTotal, $equivCurrency) }}</span>
                </div>
                <div class="rw-total-row">
                    <span class="k">{{ __('Exchange rate') }}</span>
                    <span class="v">1 USD = {{ number_format($invoice->usd_khr_rate, 0) }} KHR</span>
                </div>
                @if ($invoice->exchange_rate_source)
                    <div class="rw-total-row" style="font-size: 0.72rem; color: var(--rw-muted);">
                        <span class="k">{{ __('Rate source') }}</span>
                        <span class="v">{{ __($invoice->exchange_rate_source) }}@if ($invoice->exchange_rate_date) ({{ $invoice->exchange_rate_date->format('d M Y') }})@endif</span>
                    </div>
                @endif

                <div class="rw-total-row" style="border-top: 1px solid var(--rw-line-soft); margin-top: 0.3rem; padding-top: 0.3rem;">
                    <span class="k">{{ __('Paid') }}</span>
                    <span class="v">{{ Money::formatInvoiceAmount($invoice, 'paid') }}</span>
                </div>
                <div class="rw-balance {{ $owing ? 'owing' : '' }}">
                    <span class="k">{{ __('Balance') }}</span>
                    <span class="v" style="font-size: 0.85rem;">{{ Money::formatInvoiceAmount($invoice, 'balance') }}</span>
                </div>
            @else
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
                <div style="font-size: 0.72rem; color: var(--rw-muted); text-align: right; margin-top: 0.3rem;">
                    {{ __('Exchange-rate snapshot unavailable') }}
                </div>
            @endif
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
