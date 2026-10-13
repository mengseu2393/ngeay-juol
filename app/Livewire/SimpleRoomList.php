<?php

namespace App\Livewire;

use App\Enums\UserStatus;
use App\Livewire\Concerns\ManagesRoomList;
use App\Models\Rental;
use App\Services\QrLoginTokenService;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Simple room status list for mobile/PWA.
 * Shows all rooms for the active property with status badge and quick-action links.
 * The list, price edit and first meter reading are {@see ManagesRoomList}
 * (shared with the /m shell); the tenant popup is this screen's own.
 */
class SimpleRoomList extends Component
{
    use ManagesRoomList;

    protected $queryString = ['filter'];

    /** ID of the rental whose tenant-detail popup is open */
    public ?int $viewingRentalId = null;

    /** One-time login credentials just (re)generated — shown once, never persisted. */
    public ?string $newUsername = null;

    public ?string $newPassword = null;

    /** ID of the rental being edited in the "Edit tenant" popup */
    public ?int $editingRentalId = null;

    protected $listeners = [
        'tenant-updated' => 'handleTenantUpdated',
        // Rooms are rendered fresh in render(); a re-render is all that's needed.
        'tenancy-started' => '$refresh',
        'tenancy-ended' => '$refresh',
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

    /**
     * Passwords are hashed at rest and can never be retrieved once set — this
     * mints a fresh one (or a first one, if the tenancy is still on its unit's
     * shared room account) and surfaces it once, exactly like
     * RentalResource\Actions\TenantLogin does on the desktop panel.
     */
    public function resetTenantLogin(int $rentalId): array
    {
        $rental = $this->scopedRental($rentalId);

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $result = app(RoomAccountService::class)->createForRental($rental);

        $this->newUsername = $result['username'];
        $this->newPassword = $result['password'];

        // Returned to the client-side popup (Alpine) that shows it once.
        return ['username' => $result['username'], 'password' => $result['password']];
    }

    /**
     * Mints a single-use, short-lived QR login link for the tenant — shown on
     * the landlord's screen for the tenant to scan, signing them straight into
     * the portal with no password. Reuses the admin QR Code Login machinery
     * (QrLoginTokenService), so the QR holds only an opaque signed token.
     *
     * @return array{url: string, expires: string}
     */
    public function tenantLoginQr(int $rentalId): array
    {
        $rental = $this->scopedRental($rentalId);

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        $tenant = $rental->tenant;

        // Never mint a QR that signs into anything but a tenant account.
        abort_unless($tenant?->hasRole('tenant'), 403);

        if ($tenant->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'tenantLoginQr' => __('This account is not active, so it cannot be signed in.'),
            ]);
        }

        $issued = app(QrLoginTokenService::class)->issue($tenant, Auth::user());

        return [
            'url' => $issued['url'],
            'expires' => $issued['token']->expires_at->timezone(config('app.timezone'))->format('H:i'),
        ];
    }

    public function editTenant(int $rentalId): void
    {
        if (! $this->scopedRental($rentalId)) {
            return;
        }

        // Opened from inside the "view tenant" popup — close it first so the
        // two fixed-overlay popups don't stack (same as SimpleTenantList).
        $this->viewingRentalId = null;
        $this->editingRentalId = $rentalId;
    }

    public function closeEditTenant(): void
    {
        $this->editingRentalId = null;
    }

    public function handleTenantUpdated(): void
    {
        $this->editingRentalId = null;
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
        // The tenant popup's ID-card photos are on each card.
        return view('livewire.simple-room-list', $this->roomListData(['activeRental.media']) + [
            'viewingRental' => $this->scopedRental($this->viewingRentalId),
            'editingPriceUnit' => $this->scopedUnit($this->editingPriceUnitId),
        ]);
    }
}
