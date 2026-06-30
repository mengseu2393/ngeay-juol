<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_user');
    }

    public function view(User $user, User $model): bool
    {
        if (! $user->can('view_user')) {
            return false;
        }

        return $user->isPlatformStaff() || $this->manages($user, $model);
    }

    public function create(User $user): bool
    {
        // canCreateTenants() is true for platform staff and for delegated landlords/managers.
        return $user->can('create_user') && $user->canCreateTenants();
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('update_user')) {
            return false;
        }

        // Only a super_admin (handled by Gate::before) may modify a super_admin.
        if ($model->hasRole('super_admin')) {
            return false;
        }

        return $user->isPlatformStaff() || $this->manages($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('delete_user') || $model->is($user)) {
            return false; // no self-deletion
        }

        if ($model->hasRole('super_admin')) {
            return false;
        }

        return $user->isPlatformStaff() || $this->manages($user, $model);
    }

    /** A landlord/manager "manages" users they created or who rent one of their units. */
    protected function manages(User $user, User $model): bool
    {
        $landlordId = $user->effectiveLandlordId();

        $owners = array_filter([$user->getKey(), $landlordId]);
        if ($model->created_by_id !== null && in_array($model->created_by_id, $owners, true)) {
            return true;
        }

        return $landlordId !== null
            && $model->rentalsAsTenant()->where('landlord_id', $landlordId)->exists();
    }
}
