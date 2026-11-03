<?php

namespace App\Livewire\M2;

use App\Livewire\Concerns\BuildsMonthlyBilling;
use App\Models\Invoice;
use App\Models\PropertySetting;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/rooms/{unit}/invoice — the Room screen's "Create invoice" button: bill
 * this one room. It is the app's monthly-billing wizard
 * (Concerns\BuildsMonthlyBilling — period from the last invoice, prorated first
 * month, readings, charge rules, duplicate / low-reading checks, one
 * transaction per invoice through InvoiceBuilderService) run in its hand-picked
 * ("manual") mode for just this room's tenancy, on one page: readings, a live
 * total, a confirmation, the result.
 *
 * $rooms is a public, client-writable Livewire property; the trait re-checks
 * ownership before it writes anything. Messages are shown inline ($notice) —
 * there is no Filament here.
 */
#[Layout('m2.layout')]
class CreateInvoice extends Component
{
    use BuildsMonthlyBilling {
        mount as protected traitMount;
    }

    public Unit $unit;

    public ?string $notice = null;

    public string $noticeLevel = 'warning';

    public function mount(Unit $unit): void
    {
        abort_unless(Auth::user()?->can('create', Invoice::class), 403);

        $this->unit = $unit;
        ActiveProperty::set($unit->property_id);

        $rental = $unit->activeRental;

        if (! $rental) {
            $this->step = 'vacant';

            return;
        }

        $this->traitMount();

        if ($this->step === 'blocked') {
            return;
        }

        $this->manualMode = true;
        $this->selectedRentalIds = [$rental->id];
        $this->startBilling();
    }

    protected function notify(string $title, ?string $body = null, string $level = 'warning'): void
    {
        // The long "Billing complete" breakdown is for toasts; the result card says what happened.
        $this->notice = $title;
        $this->noticeLevel = $level;
    }

    /** A single room is billed to the date picked here (default today), not the property's next due date. */
    protected function suggestIssueDate(?int $propertyId): string
    {
        return now()->toDateString();
    }

    /** A new billing date moves the period end, so the room (and its readings) is built again. */
    public function updatedIssueDate(): void
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->issueDate) === 1
            ? Carbon::createFromFormat('!Y-m-d', $this->issueDate)
            : null;

        if (! $date || $date->format('Y-m-d') !== $this->issueDate) {
            $this->issueDate = now()->toDateString();
        }

        if ($this->step === 'reading') {
            $this->showCreateConfirmation = false;
            $this->notice = null;
            $this->loadRooms();
        }
    }

    /**
     * The due date this invoice will get, shown before it is created. It mirrors what
     * createInvoices() hands InvoiceBuilderService: the unit's due day when it has one,
     * otherwise the issue date plus the property's invoice_due_days (default 7).
     */
    public function dueDateDisplay(): ?string
    {
        $room = $this->rooms[0] ?? null;
        $rental = $this->unit->activeRental;

        if (! $room || ! $rental) {
            return null;
        }

        $due = $this->determineDueDate($rental, Carbon::parse($room['period_start']));

        if (! $due) {
            $days = PropertySetting::where('property_id', $rental->property_id)->value('invoice_due_days') ?? 7;
            $due = Carbon::parse($this->issueDate)->addDays((int) $days);
        }

        return $due->format('d M Y');
    }

    public function utilityIsNotBilled(array $utility): bool
    {
        return in_array((string) ($utility['state_override'] ?? 'normal'), ['not_applicable', 'skipped_this_cycle'], true);
    }

    public function render()
    {
        return view('livewire.m2.create-invoice', [
            'room' => $this->rooms[0] ?? null,
            'summary' => $this->rooms !== [] ? $this->roomSummary(0) : null,
            'blocked' => $this->rooms === [] || $this->firstBlockingRoomIndex() !== null,
            'createdId' => $this->resultSummary['invoice_ids'][0] ?? null,
        ])->title(__('Create invoice'));
    }
}
