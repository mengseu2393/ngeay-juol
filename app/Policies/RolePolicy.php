<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Locks the Filament Shield Role resource down. Without an explicit policy,
 * Filament defaults to "allow" for Spatie's Role model — letting any panel user
 * edit roles. Only super_admin holds `*_role` permissions (granted in the seeder),
 * and Gate::before elevates super_admin before these checks run.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_role');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('view_role');
    }

    public function create(User $user): bool
    {
        return $user->can('create_role');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('update_role');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('delete_role');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_role');
    }
}
