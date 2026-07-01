@extends('portal.layout')

@php
    $badge = fn ($status) => match ($status) {
        \App\Enums\InvoiceStatus::Paid => 'bg-green-100 text-green-700',
        \App\Enums\InvoiceStatus::Partial => 'bg-blue-100 text-blue-700',
        \App\Enums\InvoiceStatus::Pending => 'bg-amber-100 text-amber-700',
        \App\Enums\InvoiceStatus::Overdue => 'bg-red-100 text-red-700',
        \App\Enums\InvoiceStatus::Cancelled => 'bg-slate-100 text-slate-500',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

@section('content')
    <a href="{{ route('portal.dashboard') }}" class="text-sm text-emerald-700 hover:underline">&larr; {{ __('Back to invoices') }}</a>

    <div class="mt-3 rounded-xl bg-white p-5 shadow">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-lg font-bold text-slate-900">{{ $invoice->invoice_number }}</h1>
                <p class="mt-0.5 text-sm text-slate-500">
                    {{ $invoice->rental?->unit?->property?->name }}
                    @if ($invoice->rental?->unit) · {{ __('Room') }} {{ $invoice->rental->unit->room_number }}@endif
                </p>
            </div>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge($invoice->payment_status) }}">
                {{ $invoice->payment_status->getLabel() }}
            </span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
            <div><dt class="text-slate-400">{{ __('Period') }}</dt><dd>{{ $invoice->billingPeriodLabel() }}</dd></div>
            <div><dt class="text-slate-400">{{ __('Issued') }}</dt><dd>{{ \App\Models\Invoice::displayDate($invoice->issue_date) }}</dd></div>
            <div><dt class="text-slate-400">{{ __('Due') }}</dt><dd>{{ \App\Models\Invoice::displayDate($invoice->due_date) }}</dd></div>
        </dl>
    </div>

    <div class="mt-4 rounded-xl bg-white shadow">
        <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">{{ __('Charges') }}</h2>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr class="border-b border-slate-50">
                        <td class="px-5 py-2.5">
                            {{ $line->description }}
                            @if ($line->is_waived)<span class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-500">{{ __('waived') }}</span>@endif
                            @if ($line->quantity)<div class="text-xs text-slate-400">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }} × ${{ number_format((float) $line->unit_price, 4) }}</div>@endif
                        </td>
                        <td class="px-5 py-2.5 text-right font-medium">${{ number_format((float) $line->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="text-sm">
                <tr><td class="px-5 py-2 text-slate-500">{{ __('Total due') }}</td><td class="px-5 py-2 text-right font-semibold">${{ number_format((float) $invoice->amount_due, 2) }}</td></tr>
                <tr><td class="px-5 py-2 text-slate-500">{{ __('Paid') }}</td><td class="px-5 py-2 text-right text-green-700">−${{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
                <tr class="border-t border-slate-100"><td class="px-5 py-2.5 font-semibold">{{ __('Balance') }}</td><td class="px-5 py-2.5 text-right text-lg font-bold">${{ number_format((float) $invoice->balance, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>

    @if ($invoice->payments->isNotEmpty())
        <div class="mt-4 rounded-xl bg-white shadow">
            <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">{{ __('Payments') }}</h2>
            @foreach ($invoice->payments as $payment)
                <div class="flex items-center justify-between border-b border-slate-50 px-5 py-2.5 text-sm">
                    <div>
                        <p>{{ $payment->paid_at?->format('d M Y') }}</p>
                        <p class="text-xs text-slate-400">{{ $payment->method?->getLabel() }}@if ($payment->receipt_number) · {{ $payment->receipt_number }}@endif</p>
                    </div>
                    <p class="font-medium text-green-700">${{ number_format((float) $payment->amount, 2) }}</p>
                </div>
            @endforeach
        </div>
    @endif
@endsection
