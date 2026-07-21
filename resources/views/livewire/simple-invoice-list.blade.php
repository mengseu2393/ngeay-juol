<div
    class="space-y-4"
    x-data="{
        payOpen: false,
        pay: { id: null, room: '', tenant: '', balance: '', amount: '', method: '{{ \App\Enums\PaymentMethod::Cash->value }}', note: '' },
        openPay(invoice) {
            this.pay = { ...invoice, method: '{{ \App\Enums\PaymentMethod::Cash->value }}', note: '' };
            this.payOpen = true;
        },
    }"
    @pay-saved.window="payOpen = false"
>
    <style>[x-cloak] { display: none !important; }</style>

    {{-- ── Filters ── --}}
    <div class="rw-sm-filter-bar flex gap-2 overflow-x-auto pb-1">
        @foreach(['unpaid' => __('Unpaid'), 'paid' => __('Paid'), 'month' => __('This month'), 'all' => __('All')] as $val => $label)
            <button
                wire:click="$set('filter', '{{ $val }}')"
                id="invoice-filter-{{ $val }}"
                class="rw-sm-filter-pill {{ $filter === $val ? 'rw-sm-filter-active' : '' }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Room or tenant name…') }}"
            class="rw-sm-search-input"
            id="invoice-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    {{-- ── Print all (current filter) — one batch PDF via the share-aware handler ── --}}
    @if($batchIds->isNotEmpty())
        <button
            type="button"
            class="rw-sm-btn-secondary w-full flex items-center justify-center gap-1.5"
            id="invoice-print-all"
            data-stream-url="{{ route('invoices.batch-pdf', ['ids' => $batchIds->implode(','), 'mode' => 'stream']) }}"
            data-download-url="{{ route('invoices.batch-pdf', ['ids' => $batchIds->implode(',')]) }}"
            data-filename="invoices-{{ now()->format('Ymd') }}.pdf"
            data-preparing="{{ __('Preparing…') }}"
            onclick="rwPrintInvoice(this)"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v1.5A1.75 1.75 0 0 1 16.75 6H18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-.25v1.25a1.75 1.75 0 0 1-1.75 1.75h-8.5A1.75 1.75 0 0 1 4 17.25V16H3.75A2 2 0 0 1 1.75 14V8a2 2 0 0 1 2-2h1.25A1.75 1.75 0 0 1 6.75 4.25v-1.5ZM6.5 4.25c0-.138.112-.25.25-.25h6.5c.138 0 .25.112.25.25v1.5c0 .138-.112.25-.25.25h-6.5a.25.25 0 0 1-.25-.25v-1.5ZM5.5 17.25c0-.138.112-.25.25-.25h8.5c.138 0 .25.112.25.25v-3.5c0-.138-.112-.25-.25-.25h-8.5c-.138 0-.25.112-.25.25v3.5Z" clip-rule="evenodd" />
            </svg>
            <span data-label>{{ __('Print all') }} ({{ $batchIds->count() }})</span>
        </button>
    @endif

    {{-- ── Success message ── --}}
    @if($paySuccess && $paySuccessMessage)
        <div class="rw-sm-success-banner" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $paySuccessMessage }}</span>
        </div>
    @endif

    {{-- ── Pay modal (opens instantly client-side; only the Save hits the server) ── --}}
    <div
        x-cloak
        x-show="payOpen"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 px-4 pb-4 sm:pb-0"
        @keydown.escape.window="payOpen = false"
    >
        <div class="rw-sm-modal w-full max-w-sm" @click.outside="payOpen = false">
            <h3 class="rw-sm-modal-title">{{ __('Record payment') }}</h3>
            <p class="rw-sm-modal-sub">
                <span x-text="pay.room"></span>
                &bull;
                <span x-text="pay.tenant"></span>
            </p>
            <p class="rw-sm-modal-balance">
                {{ __('Balance') }}: <strong x-text="pay.balance"></strong>
            </p>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="rw-sm-label" for="pay-amount">{{ __('Amount') }}</label>
                    <input
                        type="number"
                        id="pay-amount"
                        x-model="pay.amount"
                        step="0.01"
                        min="0.01"
                        class="rw-sm-input"
                        placeholder="0.00"
                    >
                    @error('payAmount') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="pay-method">{{ __('Method') }}</label>
                    <select id="pay-method" x-model="pay.method" class="rw-sm-input">
                        @foreach($paymentMethods as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="rw-sm-label" for="pay-note">{{ __('Note (optional)') }}</label>
                    <input type="text" id="pay-note" x-model="pay.note" class="rw-sm-input" placeholder="…">
                </div>
            </div>

            <div class="mt-5 flex gap-3">
                <button type="button" @click="payOpen = false" class="rw-sm-btn-secondary flex-1" id="pay-cancel-btn">{{ __('Cancel') }}</button>
                <button
                    type="button"
                    @click="$wire.submitPayFor(pay.id, String(pay.amount), Number(pay.method), pay.note)"
                    wire:loading.attr="disabled"
                    wire:target="submitPayFor"
                    class="rw-sm-btn-primary flex-1"
                    id="pay-submit-btn"
                >
                    <span wire:loading.remove wire:target="submitPayFor">{{ __('Save') }}</span>
                    <span wire:loading wire:target="submitPayFor">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Invoice cards ── --}}
    @forelse($invoices as $invoice)
        @php
            $unit = $invoice->rental?->unit;
            $tenantName = $invoice->rental?->occupant_name ?: ($invoice->tenant?->name ?? '—');
            $statusColor = match($invoice->payment_status?->getColor()) {
                'success' => 'rw-sm-badge-success',
                'warning' => 'rw-sm-badge-warning',
                'danger'  => 'rw-sm-badge-danger',
                'info'    => 'rw-sm-badge-info',
                default   => 'rw-sm-badge-gray',
            };
            $balance = (float) $invoice->balance;
        @endphp

        <div class="rw-sm-invoice-card" id="invoice-card-{{ $invoice->id }}">
            {{-- Top row: room + status --}}
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="rw-sm-room-number">{{ $unit?->room_number ?? '—' }}</p>
                    <p class="rw-sm-tenant-name">{{ $tenantName }}</p>
                </div>
                <span class="rw-sm-badge {{ $statusColor }} shrink-0">
                    {{ $invoice->payment_status?->getLabel() ?? '—' }}
                </span>
            </div>

            {{-- Invoice details --}}
            <div class="mt-3 grid grid-cols-2 gap-y-1.5 text-sm">
                <div>
                    <p class="rw-sm-detail-label">{{ __('Invoice') }}</p>
                    <p class="rw-sm-detail-value">{{ $invoice->invoice_number }}</p>
                </div>
                <div>
                    <p class="rw-sm-detail-label">{{ __('Due date') }}</p>
                    <p class="rw-sm-detail-value">{{ \App\Models\Invoice::displayDate($invoice->due_date, 'd M Y') }}</p>
                </div>
                <div>
                    <p class="rw-sm-detail-label">{{ __('Amount') }}</p>
                    <p class="rw-sm-detail-value">{{ \App\Support\Money::formatForRecord($invoice->amount_due, $invoice) }}</p>
                </div>
                <div>
                    <p class="rw-sm-detail-label">{{ __('Balance') }}</p>
                    <p class="rw-sm-detail-value {{ $balance > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ \App\Support\Money::formatForRecord($balance, $invoice) }}
                    </p>
                </div>
            </div>

            {{-- Actions --}}
            <div class="mt-4 flex flex-col sm:flex-row gap-2">
                <a href="{{ route('invoices.view', ['invoice' => $invoice->id]) }}"
                   class="rw-sm-btn-ghost flex-1 text-center"
                   id="invoice-view-{{ $invoice->id }}"
                >{{ __('View details') }}</a>

                @if($balance > 0.009)
                    <button
                        type="button"
                        @click="openPay(@js([
                            'id' => $invoice->id,
                            'room' => $unit?->room_number ?? '—',
                            'tenant' => $tenantName,
                            'balance' => \App\Support\Money::formatForRecord($balance, $invoice),
                            'amount' => number_format($balance, 2, '.', ''),
                        ]))"
                        class="rw-sm-btn-primary flex-1"
                        id="invoice-pay-{{ $invoice->id }}"
                    >{{ __('Record payment') }}</button>
                @endif
            </div>
        </div>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No invoices found.') }}</p>
        </div>
    @endforelse

    {{-- Pagination --}}
    @if($invoices->hasPages())
        <div class="pt-2">
            {{ $invoices->links() }}
        </div>
    @endif
</div>
