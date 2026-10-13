<?php

namespace App\Livewire\Concerns;

use App\Enums\InvoiceStatus;
use App\Models\Rental;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * One tenancy, as Simple Mode's Tenants screen and the mobile shell's (/m)
 * tenant screens handle it: finding it inside the active property, its total
 * balance due, and (re)issuing the tenant-portal login.
 */
trait ManagesTenancy
{
    /** One-time login credentials just (re)generated — shown once, never persisted. */
    public ?string $newUsername = null;

    public ?string $newPassword = null;

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

    /** @return array<int, int> The payment statuses that still carry a balance. */
    public static function unpaidInvoiceStatuses(): array
    {
        return [
            InvoiceStatus::Pending->value,
            InvoiceStatus::Partial->value,
            InvoiceStatus::Overdue->value,
        ];
    }

    /**
     * Sum of every unpaid/partial/overdue invoice's balance for this tenancy,
     * formatted for display — mirrors PropertyResource::infolist()'s
     * "Outstanding" entry (USD + KHR twins, since a property can bill in
     * either currency invoice-to-invoice). Expects `invoices` loaded with only
     * the unpaid ones (see unpaidInvoiceStatuses()).
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
