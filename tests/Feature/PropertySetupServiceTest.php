<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\Unit;
use App\Services\PropertySetupService;
use Illuminate\Validation\ValidationException;

/**
 * PropertySetupService holds the writes the signup wizard used to keep inside
 * Filament (LandlordSetup) so the /m "Add property" screen ends in the very same
 * rows. The wizard's own behaviour is pinned by LandlordSetupWizardTest and
 * AddPropertyTest; these pin the one-shot create() the phone uses.
 */
class PropertySetupServiceTest extends MobileTestCase
{
    public function test_create_makes_the_property_its_settings_utilities_and_rooms_together(): void
    {
        $landlord = $this->landlord();
        $this->actingAs($landlord);

        $property = app(PropertySetupService::class)->create(
            ['name' => '  Riverside  ', 'property_type' => PropertyType::House->value, 'city' => 'Phnom Penh', 'district' => '', 'currency' => 'KHR'],
            ['Electricity' => '800', 'Water' => ''],
            ['floors' => 2, 'per_floor' => 3, 'price' => 150000],
        );

        $this->assertSame('Riverside', $property->name);
        $this->assertSame($landlord->id, $property->landlord_id);
        $this->assertSame(PropertyType::House, $property->property_type);
        $this->assertNull($property->district);
        $this->assertSame('KHR', PropertySetting::where('property_id', $property->id)->value('currency'));

        $utilities = PropertyUtility::where('property_id', $property->id)->pluck('rate', 'name');
        $this->assertEquals(800, $utilities['Electricity']);
        $this->assertEquals(0, $utilities['Water']);

        $rooms = Unit::where('property_id', $property->id)->orderBy('room_number')->get();
        $this->assertSame(['101', '102', '103', '201', '202', '203'], $rooms->pluck('room_number')->all());
        $this->assertSame(['KHR'], $rooms->pluck('rent_currency')->unique()->values()->all());
        $this->assertEquals(150000, $rooms->first()->rent_amount);
    }

    public function test_create_without_rooms_makes_no_rooms(): void
    {
        $this->actingAs($this->landlord());

        $property = app(PropertySetupService::class)->create(['name' => 'Empty', 'property_type' => 1, 'currency' => 'USD']);

        $this->assertSame(0, Unit::where('property_id', $property->id)->count());
        $this->assertSame(2, PropertyUtility::where('property_id', $property->id)->count());
    }

    /** All or nothing: a room limit hit halfway leaves no half-made property behind. */
    public function test_a_room_limit_rolls_the_whole_property_back(): void
    {
        $landlord = $this->landlord();
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_units' => 3]);
        $this->actingAs($landlord);

        try {
            app(PropertySetupService::class)->create(
                ['name' => 'Too big', 'property_type' => 1, 'currency' => 'USD'],
                [],
                ['floors' => 2, 'per_floor' => 2, 'price' => 100],
            );
            $this->fail('The room limit was not enforced.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('subscription', $e->errors());
        }

        $this->assertDatabaseMissing('properties', ['name' => 'Too big']);
        $this->assertSame(0, Unit::count());
    }

    public function test_the_property_limit_is_enforced(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'Only one');
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_properties' => 1]);
        $this->actingAs($landlord);

        $this->expectException(ValidationException::class);

        app(PropertySetupService::class)->create(['name' => 'Second', 'property_type' => 1, 'currency' => 'USD']);
    }

    public function test_room_grid_keeps_ids_and_hand_set_prices(): void
    {
        $first = PropertySetupService::roomGrid(1, 2, 100);
        $this->assertSame(['101', '102'], array_column($first, 'number'));

        $first[0]['id'] = 7;
        $first[1]['price'] = 140; // changed by hand

        $again = PropertySetupService::roomGrid(1, 3, 120, $first);

        $this->assertSame(7, $again[0]['id']);
        $this->assertEquals(120, $again[0]['price']);
        $this->assertEquals(140, $again[1]['price']);
        $this->assertNull($again[2]['id']);
    }

    public function test_save_rooms_never_deletes_a_room_that_had_a_tenancy(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rented = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->actingAs($landlord);

        app(PropertySetupService::class)->saveRooms($property, [['number' => '101', 'floor' => '1', 'price' => 90]]);

        $this->assertNotNull(Unit::find($rented->id));
        $this->assertDatabaseHas('units', ['property_id' => $property->id, 'room_number' => '101']);
        $this->assertInstanceOf(Property::class, $property->fresh());
    }
}
