<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for landlord-owned resources. Combines two layers:
 *   1. permission gate — does the role hold `{action}_{resource}` (Spatie)?
 *   2. record ownership — is the record the actor's own landlord_id?
 *
 * super_admin is handled centrally by Gate::before; platform staff (support)
 * may read across landlords. Mirrors the proven MaintenanceRequestPolicy.
 */
abstract class LandlordOwnedPolicy
{
    /** Shield-style resource slug, e.g. 'property'. */
    abstract protected function resource(): string;

    /**
     * Permission check tolerant to BOTH naming formats — my underscore form
     * (`view_any_utility_waiver`) and Shield's UI form (`view_any_utility::waiver`).
     * Compound resources differ only by the `_` vs `::` separator, so a role edited
     * via the Shield UI keeps working with these policies.
     */
    protected function allows(User $user, string $action): bool
    {
        $resource = $this->resource();

        return $user->can("{$action}_{$resource}")
            || $user->can("{$action}_".str_replace('_', '::', $resource));
    }

    /** Landlord id that owns the given record (override for indirect ownership). */
    protected function ownerId(Model $record): ?int
    {
        return $record->landlord_id;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view_any');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, 'view') && $this->owns($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'update') && $this->owns($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'delete') && $this->owns($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->allows($user, 'restore') && $this->owns($user, $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->allows($user, 'force_delete') && $this->owns($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'delete_any');
    }

    protected function owns(User $user, Model $record): bool
    {
        if ($user->isPlatformStaff()) {
            return true; // support reads across landlords; write perms still gate the action
        }

        return $this->ownerId($record) === $user->effectiveLandlordId();
    }
}
