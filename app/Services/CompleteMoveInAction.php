<?php

namespace App\Services;

use App\Enums\MoveInReadinessStatus;
use App\Enums\RentalStatus;
use App\Models\Rental;
use Illuminate\Support\Facades\DB;

class CompleteMoveInAction
{
    public function __invoke(Rental $rental, ?int $actorId = null): Rental
    {
        return DB::transaction(function () use ($rental, $actorId) {
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            $readiness = app(MoveInRuleService::class)->readiness($rental);
            if (! $readiness['ready']) {
                throw new \DomainException('Move-in requirements are not satisfied.');
            }
            if ($rental->move_in_status === MoveInReadinessStatus::Active) {
                return $rental;
            }
            // save(), NOT saveQuietly(): Rental::booted()'s saved hook is what creates the
            // first invoice (when create_invoice_on_move_in is set) and flips the tenant's
            // User to Active. Skipping it silently dropped both — and an Inactive user
            // fails canAccessPanel()/LoginController, so the tenant could not sign in.
            // The hook's mayOccupy guard is satisfied here (status Active + move_in_status
            // Active), so it occupies the room too; the explicit call below stays as a
            // no-op safety net for the requirement-less path.
            $rental->forceFill(['status' => RentalStatus::Active, 'move_in_status' => MoveInReadinessStatus::Active, 'moved_in_at' => now(), 'moved_in_by_id' => $actorId])->save();
            $rental->occupyUnit();

            return $rental->refresh();
        });
    }
}
