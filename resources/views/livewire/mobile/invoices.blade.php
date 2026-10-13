@php
    use App\Enums\PaymentMethod;
    use App\Models\Invoice;
    use App\Services\InvoicePdfService;
    use App\Support\ActiveProperty;
    use App\Support\Money;

    $filters = ['unpaid' => __('Unpaid'), 'paid' => __('Paid'), 'month' => __('This month'), 'all' => __('All')];

    $badge = fn (?string $color) => match ($color) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'danger' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    };
@endphp

<div x-data="rwMobileInvoices({{ PaymentMethod::Cash->value }}, @js(__('Something went wrong. Please try again.')))">
    <div class="flex items-end justify-between gap-3">
        <h1 class="rw-m-title">{{ __('Invoices') }}</h1>
        <a
            href="{{ route('mobile.billing') }}"
            wire:navigate
            class="rw-m-btn rw-m-btn-primary mb-3 min-h-10 px-3 text-sm"
            id="rw-m-create-invoice"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
            {{ __('Create invoice') }}
        </a>
    </div>

    @if (! ActiveProperty::id())
        <div class="rw-m-card mt-2 text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
        </div>
    @else
        {{-- Filters: one scrollable row, like a native segmented control. --}}
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Filter') }}">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    wire:click="$set('filter', '{{ $value }}')"
                    class="rw-m-pill"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    id="rw-m-invoice-filter-{{ $value }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="relative mt-3">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Room or tenant name…') }}"
                class="rw-m-input pl-11"
                id="rw-m-invoice-search"
                enterkeyhint="search"
                autocomplete="off"
            >
        </div>

        @if ($batchIds->isNotEmpty())
            <button
                type="button"
                class="rw-m-btn rw-m-btn-secondary mt-3 w-full min-h-11 text-sm"
                id="rw-m-invoice-print-all"
                data-stream-url="{{ route('invoices.batch-pdf', ['ids' => $batchIds->implode(','), 'mode' => 'stream']) }}"
                data-download-url="{{ route('invoices.batch-pdf', ['ids' => $batchIds->implode(',')]) }}"
                data-filename="invoices-{{ now()->format('Ymd') }}.pdf"
                data-preparing="{{ __('Preparing…') }}"
                onclick="rwPrintInvoice(this)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path fill-rule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v1.5A1.75 1.75 0 0 1 16.75 6H18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-.25v1.25a1.75 1.75 0 0 1-1.75 1.75h-8.5A1.75 1.75 0 0 1 4 17.25V16H3.75A2 2 0 0 1 1.75 14V8a2 2 0 0 1 2-2h1.25A1.75 1.75 0 0 1 6.75 4.25v-1.5ZM6.5 4.25c0-.138.112-.25.25-.25h6.5c.138 0 .25.112.25.25v1.5c0 .138-.112.25-.25.25h-6.5a.25.25 0 0 1-.25-.25v-1.5ZM5.5 17.25c0-.138.112-.25.25-.25h8.5c.138 0 .25.112.25.25v-3.5c0-.138-.112-.25-.25-.25h-8.5c-.138 0-.25.112-.25.25v3.5Z" clip-rule="evenodd" /></svg>
                <span data-label>{{ __('Print all') }} ({{ $batchIds->count() }})</span>
            </button>
        @endif

        <div class="mt-4 space-y-3 transition-opacity" wire:loading.class="opacity-50" wire:target="filter,search">
            @forelse ($invoices as $invoice)
                @php
                    $room = $invoice->rental?->unit?->room_number ?? '—';
                    $tenant = $invoice->rental?->occupant_name ?: ($invoice->tenant?->name ?? '—');
                    $balance = (float) $invoice->balance;
                    $balanceLabel = Money::formatForRecord($balance, $invoice);
                    $dueDate = Invoice::displayDate($invoice->due_date, 'd M Y');
                    // Everything the invoice and payment sheets need, so a tap opens them with no request.
                    $card = [
                        'id' => $invoice->id,
                        'room' => $room,
                        'tenant' => $tenant,
                        'number' => $invoice->invoice_number,
                        'balanceLabel' => $balanceLabel,
                        'amount' => number_format($balance, 2, '.', ''),
                        'canPay' => $balance > 0.009,
                        'slipUrl' => route('invoices.slip', $invoice),
                        'thermalStreamUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'size' => '58mm', 'mode' => 'stream']),
                        'thermalDownloadUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'size' => '58mm']),
                        'pdfStreamUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'mode' => 'stream']),
                        'pdfDownloadUrl' => route('invoices.pdf', ['invoice' => $invoice->id]),
                        'filename' => InvoicePdfService::filename($invoice, 'pdf'),
                        'shareText' => __('Invoice :number — :room — :amount due :date', ['number' => $invoice->invoice_number, 'room' => $room, 'amount' => $balanceLabel, 'date' => $dueDate]),
                    ];
                @endphp

                <article wire:key="rw-m-invoice-{{ $invoice->id }}" class="rw-m-card overflow-hidden p-0" id="rw-m-invoice-{{ $invoice->id }}" x-data="{ card: @js($card) }">
                    <button
                        type="button"
                        class="block w-full p-4 text-left transition-colors active:bg-slate-50 dark:active:bg-slate-800/60"
                        x-on:pointerdown="prefetch(card)"
                        x-on:click="openInvoice(card)"
                        id="rw-m-invoice-open-{{ $invoice->id }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-lg leading-tight font-bold">{{ $room }}</p>
                                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $tenant }}</p>
                            </div>
                            <span class="rw-m-badge {{ $badge($invoice->payment_status?->getColor()) }} shrink-0">
                                {{ $invoice->payment_status?->getLabel() ?? '—' }}
                            </span>
                        </div>

                        <div class="mt-3 flex items-end justify-between gap-3">
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                <p>{{ $invoice->invoice_number }}</p>
                                <p>{{ __('Due date') }}: {{ $dueDate }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Amount Due') }}</p>
                                <p class="text-lg font-bold {{ $balance > 0.009 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    {{ $balanceLabel }}
                                </p>
                            </div>
                        </div>
                    </button>

                    @if ($balance > 0.009)
                        <div class="flex justify-end border-t border-slate-100 px-4 py-2 dark:border-slate-800">
                            <button type="button" x-on:click="openPay(card)" class="rw-m-btn rw-m-btn-primary min-h-10 px-4 text-sm" id="rw-m-invoice-pay-{{ $invoice->id }}">
                                {{ __('Record payment') }}
                            </button>
                        </div>
                    @endif
                </article>
            @empty
                <div class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400">
                    {{ __('No invoices found.') }}
                </div>
            @endforelse
        </div>

        @if ($invoices->hasMorePages())
            {{-- Loads the next batch when scrolled into view; the button is the fallback. --}}
            <div x-intersect.margin.400px="$wire.loadMore()" class="mt-4">
                <button type="button" wire:click="loadMore" class="rw-m-btn rw-m-btn-secondary w-full" id="rw-m-invoice-load-more">
                    <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                    <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                </button>
            </div>
        @endif
    @endif

    {{-- ── Invoice sheet: the slip + what to do with it ── --}}
    <x-mobile.sheet name="invoice" :label="__('Invoice')">
        <div class="flex items-start justify-between gap-3 px-5 pt-2 pb-3">
            <div class="min-w-0">
                <p class="text-lg font-bold" x-text="invoice?.room"></p>
                <p class="truncate text-sm text-slate-500 dark:text-slate-400" x-text="invoice?.tenant"></p>
            </div>
            <p class="shrink-0 text-xs text-slate-500 dark:text-slate-400" x-text="invoice?.number"></p>
        </div>

        <div x-show="slipLoading" class="mx-4 h-72 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>
        {{-- Filled client-side with the invoices.slip fragment; Livewire must never morph it away. --}}
        <div id="rw-m-slip" wire:ignore x-show="! slipLoading" class="rw-m-slip px-2"></div>

        <div class="grid grid-cols-2 gap-2 px-4 pt-4">
            <button
                type="button"
                x-show="invoice?.canPay"
                x-on:click="openPay(invoice)"
                class="rw-m-btn rw-m-btn-primary col-span-2"
                id="rw-m-sheet-pay"
            >{{ __('Record payment') }}</button>

            <button
                type="button"
                onclick="rwPrintInvoice58mm(this)"
                x-bind:data-stream-url="invoice?.thermalStreamUrl"
                x-bind:data-download-url="invoice?.thermalDownloadUrl"
                x-bind:data-filename="invoice?.filename"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-m-btn rw-m-btn-secondary text-sm"
                id="rw-m-sheet-print-58mm"
            ><span data-label>{{ __('Print 58mm') }}</span></button>

            <button
                type="button"
                onclick="rwPrintInvoice(this)"
                x-bind:data-stream-url="invoice?.pdfStreamUrl"
                x-bind:data-download-url="invoice?.pdfDownloadUrl"
                x-bind:data-filename="invoice?.filename"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-m-btn rw-m-btn-secondary text-sm"
                id="rw-m-sheet-pdf"
            ><span data-label>PDF</span></button>

            <button
                type="button"
                onclick="rwShareInvoiceTelegram(this)"
                x-bind:data-download-url="invoice?.pdfDownloadUrl"
                x-bind:data-filename="invoice?.filename"
                x-bind:data-share-text="invoice?.shareText"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-m-btn col-span-2 bg-[#229ed9] text-white active:bg-[#1c8cc2]"
                id="rw-m-sheet-share"
            ><span data-label>{{ __('Share to Telegram') }}</span></button>
        </div>
    </x-mobile.sheet>

    {{-- ── Payment sheet: opens instantly from the card's data; only Save hits the server ── --}}
    <x-mobile.sheet name="pay" :label="__('Record payment')">
        <form class="space-y-4 px-5 pt-2" x-on:submit.prevent="savePay()">
            <div>
                <h2 class="text-lg font-bold">{{ __('Record payment') }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <span x-text="pay.room"></span> · <span x-text="pay.tenant"></span>
                </p>
                <p class="mt-1 text-sm">{{ __('Amount Due') }}: <strong x-text="pay.balance"></strong></p>
            </div>

            <div>
                <label for="rw-m-pay-amount" class="mb-1 block text-sm font-semibold">{{ __('Amount') }}</label>
                <input type="text" inputmode="decimal" id="rw-m-pay-amount" x-model="pay.amount" class="rw-m-input" autocomplete="off" enterkeyhint="done">
                @error('payAmount') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-pay-method" class="mb-1 block text-sm font-semibold">{{ __('Method') }}</label>
                <select id="rw-m-pay-method" x-model="pay.method" class="rw-m-input">
                    @foreach ($paymentMethods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="rw-m-pay-note" class="mb-1 block text-sm font-semibold">{{ __('Note (optional)') }}</label>
                <input type="text" id="rw-m-pay-note" x-model="pay.note" class="rw-m-input" autocomplete="off">
            </div>

            <div class="grid grid-cols-2 gap-2 pt-1">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'pay' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-pay-cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="submitPayFor" id="rw-m-pay-save">
                    <span wire:loading.remove wire:target="submitPayFor">{{ __('Save') }}</span>
                    <span wire:loading wire:target="submitPayFor">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    </x-mobile.sheet>
</div>
