<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\SimpleEditTenant;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SimpleEditTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();

        parent::tearDown();
    }

    public function test_loading_existing_rental_prefills_the_form(): void
    {
        $landlord = $this->createLandlord();
        [$property, $rental] = $this->createRental($landlord, 'Occupant A', 500);
        ActiveProperty::set($property->id);

        $this->actingAs($landlord);

        Livewire::test(SimpleEditTenant::class, ['rentalId' => $rental->id])
            ->assertSet('occupantName', 'Occupant A')
            ->assertSet('monthlyRent', '500.00');
    }

    public function test_updating_a_field_persists(): void
    {
        $landlord = $this->createLandlord();
        [$property, $rental] = $this->createRental($landlord, 'Occupant A', 500);
        ActiveProperty::set($property->id);

        $this->actingAs($landlord);

        Livewire::test(SimpleEditTenant::class, ['rentalId' => $rental->id])
            ->set('occupantName', 'Occupant A Updated')
            ->set('occupantPhone', '012345678')
            ->set('monthlyRent', '600')
            ->call('submit')
            ->assertSet('saved', true)
            ->assertDispatched('tenant-updated');

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'occupant_name' => 'Occupant A Updated',
            'occupant_phone' => '012345678',
            'monthly_rent' => 600,
        ]);
    }

    public function test_rental_from_a_different_landlords_property_returns_404(): void
    {
        $landlordA = $this->createLandlord();
        $landlordB = $this->createLandlord();
        [$propertyA, $rentalA] = $this->createRental($landlordA, 'Occupant A', 500);
        [$propertyB, $rentalB] = $this->createRental($landlordB, 'Occupant B', 400);

        // Landlord A's active property is set, but we try to open landlord B's rental.
        ActiveProperty::set($propertyA->id);

        Livewire::actingAs($landlordA)
            ->test(SimpleEditTenant::class, ['rentalId' => $rentalB->id])
            ->assertStatus(404);
    }

    public function test_occupant_name_is_required(): void
    {
        $landlord = $this->createLandlord();
        [$property, $rental] = $this->createRental($landlord, 'Occupant A', 500);
        ActiveProperty::set($property->id);

        $this->actingAs($landlord);

        Livewire::test(SimpleEditTenant::class, ['rentalId' => $rental->id])
            ->set('occupantName', '')
            ->call('submit')
            ->assertHasErrors(['occupantName' => 'required']);
    }

    private function createLandlord(): User
    {
        $user = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('landlord');

        return $user;
    }

    /** @return array{0: Property, 1: Rental} */
    private function createRental(User $landlord, string $occupantName, float $monthlyRent): array
    {
        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Prop '.uniqid(),
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => (string) random_int(100, 999),
            'room_type' => 'Standard',
            'status' => UnitStatus::Occupied,
        ]);

        $tenant = User::create([
            'name' => $occupantName,
            'email' => 'tenant-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'occupant_name' => $occupantName,
            'monthly_rent' => $monthlyRent,
            'status' => RentalStatus::Active,
            'start_date' => '2026-06-01',
        ]);

        return [$property, $rental];
    }
}
