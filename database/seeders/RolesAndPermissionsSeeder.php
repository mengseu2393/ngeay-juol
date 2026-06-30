<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 5-role RBAC from §3.2/§3.3. Permissions use Shield-style
 * `{action}_{resource}` names so the policies and the Filament Shield UI align.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** Resources whose access is constrained to the actor's landlord. */
    private array $landlordOwned = [
        'property', 'unit', 'property_utility', 'rental', 'invoice',
        'payment', 'utility_usage', 'utility_waiver', 'maintenance_request',
    ];

    /** Platform-managed catalog resources. */
    private array $platform = ['user'];

    private array $crud = ['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'restore', 'force_delete'];

    private array $extra = ['view_admin_dashboard', 'view_system_settings', 'manage_system_settings', 'view_activity_log'];

    /** Custom (non-CRUD) resource permissions — see UnitResource::getPermissionPrefixes(). */
    private array $customUnit = ['generate_rooms_unit'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ---- Permission catalog (Shield-format: compound resources use `::`) -----
        $all = [];
        foreach (array_merge($this->landlordOwned, $this->platform) as $resource) {
            foreach ($this->crud as $action) {
                $all[] = $this->p($action, $resource);
            }
        }
        foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
            $all[] = "{$action}_role"; // Shield RoleResource
        }
        $all = array_merge($all, $this->extra, $this->customUnit);

        // Drop the legacy underscore duplicates of compound resources (e.g.
        // view_any_utility_waiver) that clashed with Shield's UI `::` names.
        $compoundSlugs = array_values(array_filter(
            array_merge($this->landlordOwned, $this->platform),
            fn ($r) => str_contains($r, '_'),
        ));
        foreach (Permission::all() as $perm) {
            foreach ($compoundSlugs as $slug) {
                if (str_ends_with($perm->name, $slug)) {
                    $perm->delete();
                    break;
                }
            }
        }

        foreach (array_unique($all) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // ---- Roles --------------------------------------------------------------
        $super = Role::findOrCreate('super_admin', 'web');
        $support = Role::findOrCreate('support', 'web');
        $landlord = Role::findOrCreate('landlord', 'web');
        $manager = Role::findOrCreate('landlord_manager', 'web');
        Role::findOrCreate('tenant', 'web'); // tenant-facing only; no back-office perms

        // super_admin: everything (Gate::before also elevates, this keeps the UI honest)
        $super->syncPermissions(Permission::all());

        // support: read everything + manage non-admin users + view settings/logs
        $supportPerms = $this->readOnly(array_merge($this->landlordOwned, $this->platform));
        $supportPerms = array_merge($supportPerms, [
            'create_user', 'update_user', 'delete_user',
            'view_admin_dashboard', 'view_system_settings', 'view_activity_log',
        ]);
        $support->syncPermissions($supportPerms);

        // landlord: full CRUD over own resources (incl. their property utilities) + manage own tenants
        $landlordPerms = $this->actions($this->landlordOwned, ['view_any', 'view', 'create', 'update', 'delete', 'restore']);
        $landlordPerms = array_merge(
            $landlordPerms,
            ['view_any_user', 'view_user', 'create_user', 'update_user', 'delete_user',
                'view_admin_dashboard', 'view_activity_log'],
            $this->customUnit, // generate_rooms_unit
        );
        $landlord->syncPermissions($landlordPerms);

        // landlord_manager: delegated subset — no deletes (e.g. record readings, not change rates)
        $managerPerms = $this->actions($this->landlordOwned, ['view_any', 'view', 'create', 'update']);
        $managerPerms = array_merge(
            $managerPerms,
            ['view_any_user', 'view_user', 'create_user', 'update_user', 'view_admin_dashboard'],
            $this->customUnit, // generate_rooms_unit
        );
        $manager->syncPermissions($managerPerms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Build a permission name in Shield's format (compound resource → `::`). */
    private function p(string $action, string $resource): string
    {
        return $action.'_'.str_replace('_', '::', $resource);
    }

    private function actions(array $resources, array $actions): array
    {
        $out = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $out[] = $this->p($action, $resource);
            }
        }

        return $out;
    }

    private function readOnly(array $resources): array
    {
        return $this->actions($resources, ['view_any', 'view']);
    }
}
