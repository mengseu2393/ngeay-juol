@php
    use App\Support\Money;

    /** @var \App\Models\Invoice $invoice */
    $invoice = $getRecord();

    // Sorted in PHP, not SQL — the payments relation is eager-loaded for the whole
    // page (see InvoiceResource::table modifyQueryUsing), so re-querying here would
    // fire one extra query per visible invoice.
    $payments = $invoice->payments->sortBy('paid_at');
    $balance = (float) $invoice->balance;
@endphp
{{-- Filament wraps a ViewColumn in a `flex w-full` container, so this needs a width
     of its own or it shrinks to its content. The surrounding .fi-ta-panel already
     supplies the rounded background, so this adds no border/background of its own. --}}

<div class="w-full min-w-0">
    @if ($payments->isEmpty())
        <p class="px-3 py-2.5 text-sm text-gray-500 dark:text-gray-400">
            {{ __('No payments recorded yet.') }}
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Paid at') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Method') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Receipt number') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ __('Recorded by') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($payments as $payment)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-200">
                                {{ $payment->paid_at?->translatedFormat('d M Y') ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-end font-medium tabular-nums text-gray-950 dark:text-white">
                                {{ Money::formatForRecord($payment->amount, $payment) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <x-filament::badge color="gray" size="sm" class="inline-flex">
                                    {{ $payment->method?->getLabel() ?? '—' }}
                                </x-filament::badge>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $payment->receipt_number ?: $payment->transaction_ref ?: '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $payment->recordedBy?->name ?? '—' }}
                            </td>
                        </tr>
                        @if ($payment->note)
                            <tr>
                                <td colspan="5" class="px-3 pb-2 text-xs italic text-gray-500 dark:text-gray-400">
                                    {{ $payment->note }}
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                <tfoot class="border-t border-gray-200 dark:border-white/10">
                    <tr>
                        <td class="px-3 py-2 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Paid') }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-end font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ Money::formatForRecord($invoice->amount_paid, $invoice) }}
                        </td>
                        <td colspan="3" class="px-3 py-2 text-xs {{ $balance > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                            {{ __('Balance') }}: {{ Money::formatForRecord($balance, $invoice) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
