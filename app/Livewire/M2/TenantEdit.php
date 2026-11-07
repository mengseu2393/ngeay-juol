<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\TenantEdit as MobileTenantEdit;
use App\Models\Rental;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * /m2/tenants/{rentalId}/edit — the tenant's details, emergency contact, ID card
 * photos, rent and deposit. Fields, validation, permissions and the save are
 * /m's TenantEdit (SimpleEditTenant); here it is a screen of its own instead of
 * a bottom sheet, and a save goes back to the tenant with a toast.
 */
#[Layout('m2.layout')]
class TenantEdit extends MobileTenantEdit
{
    /** Fixed by the URL. */
    #[Locked]
    public ?int $rentalId = null;

    /** A failed validation or permission check throws, so getting past parent::submit() means it saved. */
    public function submit(): void
    {
        parent::submit();

        session()->flash('m2-toast', __('Tenant details saved'));
        $this->redirectRoute('m2.tenant', ['rental' => $this->rentalId], navigate: true);
    }

    /**
     * Any of this landlord's tenancies (LandlordScope), whichever property is open:
     * /m2 lists rooms across properties, so a link here must not 404 for that reason.
     */
    protected function scopedRental(): ?Rental
    {
        return $this->rentalId ? Rental::query()->find($this->rentalId) : null;
    }

    public function render()
    {
        return view('livewire.m2.tenant-edit', parent::render()->getData() + [
            'rental' => $this->scopedRental()->load('unit'),
        ])->title(__('Edit tenant'));
    }
}
