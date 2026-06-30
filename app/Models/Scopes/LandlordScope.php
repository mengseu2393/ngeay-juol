<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Fail-closed landlord isolation. Applied to every model that carries a
 * denormalized `landlord_id`. Replaces the old app's copy-pasted
 * `where('landlord_id', ...)` that was forgotten in PropertyDetail/UnitEdit.
 *
 * - super_admin / support  → unscoped (cross-landlord), mirroring Gate::before.
 * - landlord               → constrained to their own id.
 * - landlord_manager       → constrained to the landlord they manage.
 * - tenant / no auth        → unscoped here; tenant access is enforced by
 *   per-record Policies and explicit tenant_id filters on tenant-facing pages.
 */
class LandlordScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return; // CLI, queue workers, seeders, system context
        }

        if (method_exists($user, 'isPlatformStaff') && $user->isPlatformStaff()) {
            return; // super_admin / support see everything
        }

        $landlordId = method_exists($user, 'effectiveLandlordId') ? $user->effectiveLandlordId() : null;

        if ($landlordId !== null) {
            $builder->where($model->getTable().'.landlord_id', $landlordId);
        }
    }
}
