<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\UserStatus;
use App\Livewire\SimpleUtilityRates;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Simple Mode's Utility Rates screen replaces the ?from=simple detour through
 * PropertyUtilityResource's list page. The add/edit popup is client-side; the
 * component only exposes one-shot save / toggle / delete calls, all scoped to
 * the active property.
 */
class SimpleUtilityRatesTest extends TestCase
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

    public function test_lists_and_filters_the_active_propertys_utilities(): void
    {
        [$landlord, $property] = $this->setup_property();
        $this->utility($property, ['name' => 'Electricity', 'is_active' => true]);
        $this->utility($property, ['name' => 'Water', 'is_active' => false]);

        $other = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other']);
        $this->utility($other, ['name' => 'Gas']);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityRates::class)
            ->assertSee('EDC-Electricity')->assertSee('EDC-Water')->assertDontSee('EDC-Gas')
            ->set('filter', 'active')
            ->assertSee('EDC-Electricity')->assertDontSee('EDC-Water')
            ->set('filter', 'inactive')
            ->assertDontSee('EDC-Electricity')->assertSee('EDC-Water');
    }

    public function test_save_creates_a_utility_on_the_active_property_with_its_currency(): void
    {
        [$landlord, $property] = $this->setup_property();

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityRates::class)
            ->call('save', null, [
                'name' => 'Water',
                'billing_type' => BillingType::Metered->value,
                'rate' => '0.30',
                'unit_of_measure' => 'm³',
                'provider' => 'PPWSA',
                'account_ref' => '',
                'is_active' => true,
            ])
            ->assertHasNoErrors()
            ->assertDispatched('utility-rate-saved');

        $this->assertDatabaseHas('property_utilities', [
            'property_id' => $property->id,
            'name' => 'Water',
            'provider' => 'PPWSA',
            'is_active' => 1,
        ]);
    }

    public function test_save_validates_the_rate_and_keeps_errors_on_the_form(): void
    {
        [$landlord] = $this->setup_property();

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityRates::class)
            ->call('save', null, [
                'name' => 'Water',
                'billing_type' => BillingType::Metered->value,
                'rate' => '-1',
                'unit_of_measure' => 'm³',
            ])
            ->assertHasErrors(['rate']);
    }

    public function test_save_updates_toggle_flips_and_delete_soft_deletes_a_scoped_utility(): void
    {
        [$landlord, $property] = $this->setup_property();
        $utility = $this->utility($property, ['name' => 'Electricity', 'rate' => 0.25]);

        $component = Livewire::actingAs($landlord)->test(SimpleUtilityRates::class);

        $component->call('save', $utility->id, [
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered->value,
            'rate' => '0.28',
            'unit_of_measure' => 'kWh',
            'provider' => 'EDC',
            'is_active' => true,
        ])->assertHasNoErrors();
        $this->assertEquals(0.28, (float) $utility->fresh()->rate);

        $component->call('toggleActive', $utility->id);
        $this->assertFalse($utility->fresh()->is_active);

        $component->call('delete', $utility->id)->assertDispatched('utility-rate-deleted');
        $this->assertSoftDeleted('property_utilities', ['id' => $utility->id]);
    }

    public function test_actions_404_for_a_utility_outside_the_active_property(): void
    {
        [$landlord] = $this->setup_property();
        $other = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other']);
        $foreign = $this->utility($other, ['name' => 'Gas']);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityRates::class)
            ->call('toggleActive', $foreign->id)
            ->assertStatus(404);
    }

    private function setup_property(): array
    {
        $landlord = User::factory()->create([
            'name' => 'Landlord '.uniqid(),
            'email' => 'landlord-utility-rates-'.uniqid().'@example.com',
        ]);
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Test Property']);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        return [$landlord, $property];
    }

    private function utility(Property $property, array $attributes = []): PropertyUtility
    {
        return PropertyUtility::create($attributes + [
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'provider' => 'EDC-'.($attributes['name'] ?? 'x'),
            'is_active' => true,
        ]);
    }
}
