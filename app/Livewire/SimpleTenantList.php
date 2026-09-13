<?php

namespace App\Livewire;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
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

    /** ID of the rental whose tenant-detail popup is open */
    public ?int $viewingRentalId = null;

    /** One-time login credentials just (re)generated — shown once, never persisted. */
    public ?string $newUsername = null;

    public ?string $newPassword = null;

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
    private function scopedRental(?int $rentalId): ?Rental
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
                ->with(['unit', 'tenant'])
                ->whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
                ->where('status', RentalStatus::Active->value)
                ->when($this->search !== '', function ($q) {
                    $s = '%'.trim($this->search).'%';
                    $q->where(function ($q) use ($s) {
                        $q->where('occupant_name', 'like', $s)
                            ->orWhere('occupant_phone', 'like', $s)
                            ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', $s))
                            ->orWhereHas('unit', fn ($uq) => $uq->where('room_number', 'like', $s));
                    });
                })
                ->get()
                ->sortBy(fn (Rental $rental) => $rental->unit?->room_number)
                ->values()
            : collect();

        return view('livewire.simple-tenant-list', [
            'tenants' => $tenants,
            'viewingRental' => $this->scopedRental($this->viewingRentalId),
        ]);
    }
}
