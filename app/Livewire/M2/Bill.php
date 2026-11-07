<?php

namespace App\Livewire\M2;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /m2/rooms/{unit}/bill — sketch 2's "Acc Bill": the room, each charge of its
 * bill (water, electricity, rent…) with its amount, and the total. Shows the
 * newest invoice of the room across all its tenancies; the pills above pick an
 * earlier one.
 *
 * "Record payment" takes money against the invoice on screen, through
 * Invoice::recordPayment() (the ledger updates amount_paid and the status). Like
 * the panel's action it is hidden on a Paid or Cancelled invoice, and it is paid
 * in the invoice's own currency. Unlike the panel, more than the balance is
 * refused: /m2 can't undo a payment, so a mistyped amount would be stuck.
 */
#[Layout('m2.layout')]
class Bill extends Component
{
    public Unit $unit;

    /** An earlier invoice picked from the pills (?invoice=); anything not this room's is ignored. */
    protected $queryString = ['invoiceId' => ['as' => 'invoice', 'except' => null]];

    public ?int $invoiceId = null;

    /** The invoice the payment form is open for; only ever set on the server, from this room's invoices. */
    #[Locked]
    public ?int $payingInvoiceId = null;

    public string $payAmount = '';

    public int $payMethod = PaymentMethod::Cash->value;

    public string $payNote = '';

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function pick(int $invoiceId): void
    {
        $this->invoiceId = $invoiceId;
        $this->cancelPay();
    }

    public function startPay(): void
    {
        $invoice = $this->displayedInvoice($this->recentInvoices());

        abort_unless($invoice && $this->canPay($invoice), 403);

        $this->resetErrorBag();
        $this->payingInvoiceId = $invoice->id;
        $this->payAmount = number_format((float) $invoice->balance, Money::decimals(Money::forRecord($invoice)), '.', '');
        $this->payMethod = PaymentMethod::Cash->value;
        $this->payNote = '';
    }

    public function cancelPay(): void
    {
        $this->payingInvoiceId = null;
        $this->resetErrorBag();
    }

    public function submitPay(): void
    {
        abort_unless(Auth::user()?->can('create', Payment::class), 403);

        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01'],
            'payMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'payNote' => ['nullable', 'string', 'max:255'],
        ], [
            'payAmount.required' => __('Enter the amount received.'),
            'payAmount.numeric' => __('Enter the amount received.'),
            'payAmount.min' => __('The amount must be more than zero.'),
        ]);

        $invoice = $this->payingInvoiceId
            ? Invoice::query()
                ->whereKey($this->payingInvoiceId)
                ->whereHas('rental', fn ($q) => $q->where('unit_id', $this->unit->id))
                ->first()
            : null;

        abort_unless($invoice, 404);

        // Read again here: a double tap, or a payment taken on another phone, has already changed the balance.
        if (! $this->hasBalance($invoice)) {
            $this->addError('payAmount', __('This invoice has nothing left to pay.'));

            return;
        }

        if ((float) $this->payAmount - (float) $invoice->balance > 0.004) {
            $this->addError('payAmount', __('The amount is more than the balance of :balance.', [
                'balance' => Money::formatForRecord($invoice->balance, $invoice),
            ]));

            return;
        }

        $invoice->recordPayment([
            'recorded_by_id' => Auth::id(),
            'amount' => (float) $this->payAmount,
            'currency' => Money::forRecord($invoice),
            'paid_at' => now(),
            'method' => PaymentMethod::from((int) $this->payMethod),
            'note' => filled($this->payNote) ? trim($this->payNote) : null,
        ]);

        $invoice->refresh();

        $this->payingInvoiceId = null;
        $this->dispatch('rw-m-toast', message: (float) $invoice->balance > 0.004
            ? __('Payment saved. Balance: :balance', ['balance' => Money::formatForRecord($invoice->balance, $invoice)])
            : __('Invoice fully paid.'));
    }

    private function hasBalance(Invoice $invoice): bool
    {
        return ! $invoice->payment_status?->isSettled() && (float) $invoice->balance > 0.004;
    }

    private function canPay(Invoice $invoice): bool
    {
        return $this->hasBalance($invoice) && (bool) Auth::user()?->can('create', Payment::class);
    }

    /** @return Collection<int, Invoice> */
    private function recentInvoices(): Collection
    {
        return $this->unit->invoices()
            ->orderByDesc('invoices.issue_date')->orderByDesc('invoices.id')
            ->limit(6)
            ->get();
    }

    /** @param  Collection<int, Invoice>  $recent */
    private function displayedInvoice(Collection $recent): ?Invoice
    {
        return $recent->firstWhere('id', $this->invoiceId) ?? $recent->first();
    }

    public function render()
    {
        $recent = $this->recentInvoices();
        $invoice = $this->displayedInvoice($recent);

        $invoice?->load(['lines', 'rental', 'property.settings']);

        return view('livewire.m2.bill', [
            'unit' => $this->unit,
            'recent' => $recent,
            'invoice' => $invoice,
            'lines' => $invoice ? $invoice->lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice())->values() : collect(),
            'canPay' => $invoice && $this->canPay($invoice),
            'paymentMethods' => PaymentMethod::options(),
        ])->title(__('Bill'));
    }
}
