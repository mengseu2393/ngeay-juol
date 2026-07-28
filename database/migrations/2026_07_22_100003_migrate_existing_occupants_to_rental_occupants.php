<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy existing occupant data from the rentals table into rental_occupants.
        // Each rental that has an occupant_name gets a "primary" occupant record.
        $rentals = DB::table('rentals')
            ->whereNotNull('occupant_name')
            ->where('occupant_name', '!=', '')
            ->get();

        foreach ($rentals as $rental) {
            DB::table('rental_occupants')->insert([
                'rental_id'                      => $rental->id,
                'user_id'                        => $rental->tenant_id,
                'role'                           => 'primary',
                'occupant_name'                  => $rental->occupant_name,
                'occupant_phone'                 => $rental->occupant_phone ?? null,
                'occupant_id_card'               => $rental->occupant_id_card ?? null,
                'occupant_address'               => $rental->occupant_address ?? null,
                'occupant_gender'                => $rental->occupant_gender ?? null,
                'occupant_dob'                   => $rental->occupant_dob ?? null,
                'occupant_nationality'           => $rental->occupant_nationality ?? null,
                'occupant_workplace'             => $rental->occupant_workplace ?? null,
                'emergency_contact_name'         => $rental->emergency_contact_name ?? null,
                'emergency_contact_phone'        => $rental->emergency_contact_phone ?? null,
                'emergency_contact_relationship' => $rental->emergency_contact_relationship ?? null,
                'guarantor_name'                 => $rental->guarantor_name ?? null,
                'guarantor_phone'                => $rental->guarantor_phone ?? null,
                'guarantor_id_number'            => $rental->guarantor_id_number ?? null,
                'guarantor_address'              => $rental->guarantor_address ?? null,
                'created_at'                     => $rental->created_at,
                'updated_at'                     => now(),
            ]);
        }
    }

    public function down(): void
    {
        // On rollback, delete all rental_occupants that were auto-migrated.
        // We don't delete co-tenants that might have been manually added later.
        DB::table('rental_occupants')->where('role', 'primary')->delete();
    }
};
