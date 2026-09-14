<?php

namespace App\Livewire;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Simple tenant directory for mobile/PWA — every active tenancy in the active
 * property, searchable by tenant name, phone, or room number. Tapping a row
 * opens the same tenant-detail popup SimpleRoomList uses (duplicated here
 * rather than shared — a third copy would be the signal to extract a
 * partial, per this repo's convention).
 */
class SimpleTenantList extends Component
{
    public string $search = '';

    /** active | due | ended | all */
    public string $filter = 'active';

    protected $queryString = ['filter'];

    /** ID of the rental whose tenant-detail popup is open */
    public ?int $viewingRentalId = null;

    /** One-time login credentials just (re)generated — shown once, never persisted. */
    public ?string $newUsername = null;

    public ?string $newPassword = null;

    /** Whether the "Add tenant" popup is open */
    public bool $showAddTenant = false;

    /** ID of the rental being edited in the "Edit tenant" popup */
    public ?int $editingRentalId = null;

    /** ID of the rental whose "End tenancy" popup is open */
    public ?int $endingRentalId = null;

    protected $listeners = [
        'tenant-updated' => 'handleTenantUpdated',
        'tenancy-ended' => 'handleTenancyEnded',
    ];

    public function updatingSearch(): void
    {
        // no pagination to reset
    }

    public function viewTenant(int $rentalId): void
    {
        $rental = $this->scopedRental($rentalId);

        if (! $rental) {
            return;
        }

        $this->viewingRentalId = $rentalId;
        $this->newUsername = null;
        $this->newPassword = null;
    }

    public function closeTenantView(): void
    {
        $this->viewingRentalId = null;
        $this->newUsername = null;
        $this->newPassword = null;
    }

    public function openAddTenant(): void
    {
        $this->showAddTenant = true;
    }

    public function closeAddTenant(): void
    {
        $this->showAddTenant = false;
    }

    public function editTenant(int $rentalId): void
    {
        if (! $this->scopedRental($rentalId)) {
            return;
        }

        // Opened from inside the "view tenant" popup — close it first so the
        // two fixed-overlay popups don't stack (same z-index, later DOM node
        // wins, leaving the edit popup rendered behind the view popup).
        $this->viewingRentalId = null;
        $this->editingRentalId = $rentalId;
    }

    public function closeEditTenant(): void
    {
        $this->editingRentalId = null;
    }

    public function endTenancy(int $rentalId): void
    {
        if (! $this->scopedRental($rentalId)) {
            return;
        }

        // Same reasoning as editTenant() above — close the view popup so it
        // doesn't stack on top of this one.
        $this->viewingRentalId = null;
        $this->endingRentalId = $rentalId;
    }

    public function closeEndTenancy(): void
    {
        $this->endingRentalId = null;
    }

    public function handleTenantUpdated(): void
    {
        $this->editingRentalId = null;
    }

    public function handleTenancyEnded(): void
    {
        $this->endingRentalId = null;
        $this->viewingRentalId = null;
    }

    /**
     * Passwords are hashed at rest and can never be retrieved once set — this
     * mints a fresh one (or a first one, if the tenancy is still on its unit's
     * shared room account) and surfaces it once, exactly like
     * RentalResource\Actions\TenantLogin does on the desktop panel.
     */
    public function resetTenantLogin(int $rentalId): void
    {
        $rental = $this->scopedRental($rentalId);

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $result = app(RoomAccountService::class)->createForRental($rental);

        $this->newUsername = $result['username'];
        $this->newPassword = $result['password'];
    }

    /** The rental, scoped to the active property — null if missing/foreign. */
    public function scopedRental(?int $rentalId): ?Rental
    {
        if (! $rentalId) {
            return null;
        }

        return Rental::query()
            ->with(['tenant', 'unit.property'])
            ->when(ActiveProperty::id(), fn ($q) => $q->whereHas('unit', fn ($uq) => $uq->where('property_id', ActiveProperty::id())))
            ->whereKey($rentalId)
            ->first();
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();

        $tenants = $propertyId
            ? Rental::query()
                ->with(['unit', 'tenant', 'occupants', 'invoices' => fn ($q) => $q->whereIn('payment_status', [
                    InvoiceStatus::Pending->value,
                    InvoiceStatus::Partial->value,
                    InvoiceStatus::Overdue->value,
                ])])
                ->whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
                ->when($this->filter === 'active' || $this->filter === 'due', fn ($q) => $q->where('status', RentalStatus::Active->value))
                ->when($this->filter === 'ended', fn ($q) => $q->where('status', '!=', RentalStatus::Active->value))
                ->when($this->filter === 'due', fn ($q) => $q->whereHas('invoices', fn ($iq) => $iq->whereIn('payment_status', [
                    InvoiceStatus::Pending->value,
                    InvoiceStatus::Partial->value,
                    InvoiceStatus::Overdue->value,
                ])))
                ->when($this->search !== '', function ($q) {
                    $s = '%'.trim($this->search).'%';
                    $q->where(function ($q) use ($s) {
                        $q->where('occupant_name', 'like', $s)
                            ->orWhere('occupant_phone', 'like', $s)
                            ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', $s))
                            ->orWhereHas('occupants', fn ($oq) => $oq->where('occupant_name', 'like', $s)->orWhere('occupant_phone', 'like', $s))
                            ->orWhereHas('unit', fn ($uq) => $uq->where('room_number', 'like', $s));
                    });
                })
                ->get()
                ->sortBy(fn (Rental $rental) => mb_strtolower($rental->occupant_name ?: ($rental->tenant?->name ?? '')))
                ->values()
            : collect();

        return view('livewire.simple-tenant-list', [
            'tenants' => $tenants,
            'viewingRental' => $this->scopedRental($this->viewingRentalId),
        ]);
    }

    /**
     * Sum of every unpaid/partial/overdue invoice's balance for this tenancy,
     * formatted for display — mirrors PropertyResource::infolist()'s
     * "Outstanding" entry (USD + KHR twins, since a property can bill in
     * either currency invoice-to-invoice).
     */
    public static function totalDueFor(Rental $rental): string
    {
        $usdTotal = 0.0;
        $khrTotal = 0.0;

        foreach ($rental->invoices as $invoice) {
            $usdTotal += $invoice->balance_usd;
            $khrTotal += $invoice->balance_khr;
        }

        if ($usdTotal <= 0 && $khrTotal <= 0) {
            return Money::format(0, 'USD');
        }

        $parts = array_filter([
            $usdTotal > 0 ? Money::format($usdTotal, 'USD') : null,
            $khrTotal > 0 ? Money::format($khrTotal, 'KHR') : null,
        ]);

        return implode(' / ', $parts);
    }
}
