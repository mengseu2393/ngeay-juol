<?php

namespace Tests\Feature;

use App\Enums\OccupantRole;
use App\Enums\RentalStatus;
use App\Models\Property;
use App\Models\Rental;
use App\Models\RentalOccupant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiOccupantFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_can_have_primary_cotenants_and_dependents(): void
    {
        $landlord = User::factory()->create();
        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'max_occupants' => 4,
        ]);
        $tenantUser = User::factory()->create();

        $rental = Rental::create([
            'unit_id' => $unit->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenantUser->id,
            'occupant_name' => 'Sokha Meng',
            'monthly_rent' => 500,
            'start_date' => now(),
            'status' => RentalStatus::Active,
        ]);

        $primary = $rental->occupants()->create([
            'role' => OccupantRole::Primary,
            'user_id' => $tenantUser->id,
            'occupant_name' => 'Sokha Meng',
        ]);

        $coTenant = $rental->occupants()->create([
            'role' => OccupantRole::CoTenant,
            'occupant_name' => 'Jane Doe',
            'occupant_phone' => '012345678',
        ]);

        $dependent = $rental->occupants()->create([
            'role' => OccupantRole::Dependent,
            'occupant_name' => 'Little Meng',
        ]);

        $this::assertCount(3, $rental->occupants);
        $this::assertEquals('Sokha Meng', $rental->primaryOccupant->occupant_name);
        $this::assertTrue($primary->isPrimary());
        $this::assertFalse($coTenant->isPrimary());
        $this::assertEquals('Sokha Meng, Jane Doe, Little Meng', $rental->occupant_names);
    }
}
