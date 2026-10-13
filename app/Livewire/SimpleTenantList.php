<?php

namespace App\Livewire;

use App\Livewire\Concerns\ListsTenancies;
use Livewire\Component;

/**
 * Simple tenant directory for mobile/PWA — every active tenancy in the active
 * property, searchable by tenant name, phone, or room number. Tapping a row
 * opens the same tenant-detail popup SimpleRoomList uses (duplicated here
 * rather than shared — a third copy would be the signal to extract a
 * partial, per this repo's convention).
 *
 * The list, its filters, rental scoping, the balance due and the portal-login
 * reset are Concerns\ListsTenancies (shared with the mobile shell's
 * Mobile\Tenants and Mobile\Tenant).
 */
class SimpleTenantList extends Component
{
    use ListsTenancies;

    protected $queryString = ['filter'];

    /** ID of the rental whose tenant-detail popup is open */
    public ?int $viewingRentalId = null;

    /** Whether the "Add tenant" popup is open */
    public bool $showAddTenant = false;

    /** ID of the rental being edited in the "Edit tenant" popup */
    public ?int $editingRentalId = null;

    /** ID of the rental whose "End tenancy" popup is open */
    public ?int $endingRentalId = null;

    protected $listeners = [
        'tenant-updated' => 'handleTenantUpdated',
        'tenancy-ended' => 'handleTenancyEnded',
        // Tenants and their balances are rendered fresh in render().
        'tenancy-started' => '$refresh',
        'invoices-created' => '$refresh',
        'pay-saved' => '$refresh',
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

    public function render()
    {
        return view('livewire.simple-tenant-list', [
            'tenants' => $this->tenancyList(),
            'viewingRental' => $this->scopedRental($this->viewingRentalId),
        ]);
    }
}
