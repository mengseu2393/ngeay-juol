<?php

namespace App\Livewire;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Support\ActiveProperty;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Simple invoice card list for mobile/PWA use.
 * Shows invoices scoped to the active property with simple filters (Unpaid / Paid / This month).
 * Inline payment recording uses Invoice::recordPayment() — never writes to amount_paid directly.
 */
class SimpleInvoiceList extends Component
{
    use WithPagination;

    /** Filter: 'unpaid' | 'paid' | 'month' | 'all' */
    public string $filter = 'unpaid';

    public string $search = '';

    /** ID of the invoice being paid right now */
    public ?int $payingInvoiceId = null;

    public string $payAmount = '';
    public int $payMethod = 1; // PaymentMethod::Cash = 1
    public string $payNote = '';

    public bool $paySuccess = false;
    public ?string $paySuccessMessage = null;

    protected $queryString = ['filter', 'search'];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startPay(int $invoiceId): void
    {
        $this->payingInvoiceId = $invoiceId;
        $invoice = $this->loadInvoice($invoiceId);
        $this->payAmount = $invoice ? number_format((float) $invoice->balance, 2, '.', '') : '';
        $this->payMethod = PaymentMethod::Cash->value;
        $this->payNote = '';
        $this->paySuccess = false;
        $this->paySuccessMessage = null;
    }

    public function cancelPay(): void
    {
        $this->payingInvoiceId = null;
        $this->paySuccess = false;
    }

    /**
     * One-shot payment save for the client-side (Alpine) modal: the modal opens
     * instantly on the phone with data already in the DOM, so the only server
     * round-trip is this save. Validation and scoping stay identical to submitPay().
     */
    public function submitPayFor(int $invoiceId, string $amount, int $method, string $note = ''): void
    {
        $this->payingInvoiceId = $invoiceId;
        $this->payAmount = $amount;
        $this->payMethod = $method;
        $this->payNote = $note;

        $this->submitPay();
    }

    public function submitPay(): void
    {
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payMethod' => 'required|integer',
        ]);

        $invoice = $this->loadInvoice($this->payingInvoiceId);

        if (! $invoice) {
            $this->addError('payAmount', __('Invoice not found.'));
            return;
        }

        $method = PaymentMethod::tryFrom((int) $this->payMethod) ?? PaymentMethod::Cash;

        $invoice->recordPayment([
            'recorded_by_id' => Auth::id(),
            'amount' => (float) $this->payAmount,
            'paid_at' => now(),
            'method' => $method,
            'note' => $this->payNote ?: null,
        ]);

        $invoice->refresh();
        $remaining = (float) $invoice->balance;

        $this->paySuccess = true;
        $this->paySuccessMessage = $remaining > 0
            ? __('Payment saved. Balance: :balance', ['balance' => Money::formatForRecord($remaining, $invoice)])
            : __('Invoice fully paid.');

        $this->payingInvoiceId = null;
        $this->resetPage();
        $this->dispatch('pay-saved');
    }

    private function loadInvoice(?int $id): ?Invoice
    {
        if (! $id) return null;

        return Invoice::query()
            ->with(['lines.utilityUsage.propertyUtility', 'rental.unit.property', 'tenant', 'property'])
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($id)
            ->first();
    }

    /** The invoice query matching the current filter + search (unpaginated). */
    protected function filteredQuery()
    {
        $propertyId = ActiveProperty::id();

        $query = Invoice::query()
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->when(! $propertyId, fn ($q) => $q->whereRaw('1 = 0')); // no property → empty list

        // Filter
        match ($this->filter) {
            'unpaid' => $query->whereIn('payment_status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Overdue->value,
                InvoiceStatus::Partial->value,
            ]),
            'paid' => $query->where('payment_status', InvoiceStatus::Paid->value),
            'month' => $query->whereYear('period_start', now()->year)
                             ->whereMonth('period_start', now()->month),
            default => null,
        };

        // Search by room number or tenant/occupant name
        if ($this->search !== '') {
            $s = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($s) {
                $q->whereHas('rental.unit', fn ($uq) => $uq->where('room_number', 'like', $s))
                  ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', $s))
                  ->orWhereHas('rental', fn ($rq) => $rq->where('occupant_name', 'like', $s));
            });
        }

        return $query;
    }

    public function render()
    {
        $invoices = $this->filteredQuery()
            ->with(['rental.unit', 'tenant'])
            ->orderByDesc('issue_date')
            ->paginate(15);

        // Everything the current filter matches (not just this page) — feeds the
        // "Print all" batch-PDF button. Same cap as the batch route.
        $batchIds = $this->filteredQuery()->orderByDesc('issue_date')->limit(200)->pluck('id');

        $paymentMethods = collect(PaymentMethod::cases())
            ->mapWithKeys(fn ($m) => [$m->value => $m->getLabel()])
            ->all();

        return view('livewire.simple-invoice-list', [
            'invoices' => $invoices,
            'paymentMethods' => $paymentMethods,
            'batchIds' => $batchIds,
        ]);
    }
}
