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
    <h1 class="text-xl font-bold text-slate-900">{{ __('Hi') }}{{ $user->name ? ', '.$user->name : '' }} 👋</h1>

    @if ($unit)
        <div class="mt-3 rounded-xl bg-white p-4 shadow">
            <p class="text-xs uppercase tracking-wide text-slate-400">{{ __('Your room') }}</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">
                {{ __('Room') }} {{ $unit->room_number }}
                @if ($unit->property)<span class="text-slate-400">·</span> <span class="text-slate-600">{{ $unit->property->name }}</span>@endif
            </p>
        </div>
    @endif

    <h2 class="mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Invoices') }}</h2>

    @forelse ($invoices as $invoice)
        <a href="{{ route('portal.invoice', $invoice) }}"
           class="mt-3 flex items-center justify-between rounded-xl bg-white p-4 shadow transition hover:shadow-md">
            <div>
                <p class="font-semibold text-slate-900">{{ $invoice->invoice_number }}</p>
                <p class="mt-0.5 text-sm text-slate-500">
                    {{ $invoice->period_start?->format('d M') }} – {{ $invoice->period_end?->format('d M Y') }}
                </p>
                <p class="mt-1 text-xs text-slate-400">{{ __('Due') }} {{ $invoice->due_date?->format('d M Y') }}</p>
            </div>
            <div class="text-right">
                <p class="text-lg font-bold text-slate-900">${{ number_format((float) $invoice->amount_due, 2) }}</p>
                @if ((float) $invoice->balance > 0)
                    <p class="text-xs text-slate-500">{{ __('Balance') }} ${{ number_format((float) $invoice->balance, 2) }}</p>
                @endif
                <span class="mt-1 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge($invoice->payment_status) }}">
                    {{ $invoice->payment_status->getLabel() }}
                </span>
            </div>
        </a>
    @empty
        <div class="mt-3 rounded-xl bg-white p-6 text-center text-slate-500 shadow">
            {{ __('No invoices yet.') }}
        </div>
    @endforelse
@endsection
