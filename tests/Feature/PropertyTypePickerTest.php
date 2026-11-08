<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Livewire\Mobile\AddProperty;
use App\Livewire\Mobile\PropertySettings;
use App\Models\Property;
use Livewire\Livewire;

/**
 * The owner cut the property-type pickers down to the two kinds their landlords
 * actually let: a whole house, or a house split into rooms. The other enum cases
 * (Condo, Villa, …) stay because properties saved before still carry them — so a
 * picker on such a property must keep its own kind instead of failing validation
 * or quietly switching it.
 */
class PropertyTypePickerTest extends MobileTestCase
{
    public function test_pickers_offer_only_room_rental_and_whole_house(): void
    {
        $this->assertSame(
            [PropertyType::RoomRental->value => __('Room rental'), PropertyType::House->value => __('Whole house')],
            PropertyType::pickerOptions(),
        );
        $this->assertSame(PropertyType::RoomRental, PropertyType::default());
    }

    public function test_a_retired_kind_stays_in_the_picker_of_the_property_that_has_it(): void
    {
        $this->assertArrayHasKey(PropertyType::Condo->value, PropertyType::pickerOptions(PropertyType::Condo));
        $this->assertArrayNotHasKey(PropertyType::Villa->value, PropertyType::pickerOptions(PropertyType::Condo));
        // Filters still list every kind.
        $this->assertCount(count(PropertyType::cases()), PropertyType::options());
    }

    public function test_a_new_property_starts_as_room_rental_and_cannot_pick_a_retired_kind(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddProperty::class)
            ->assertSet('propertyType', PropertyType::RoomRental->value)
            ->set('name', 'Villa One')
            ->set('propertyType', PropertyType::Villa->value)
            ->set('withRooms', false)
            ->call('save')
            ->assertHasErrors(['propertyType']);

        $this->assertDatabaseMissing('properties', ['name' => 'Villa One']);
    }

    public function test_editing_an_old_condo_property_keeps_its_kind(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $property->update(['property_type' => PropertyType::Condo]);
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class)
            ->assertSet('details.property_type', PropertyType::Condo->value)
            ->set('details.name', 'Renamed')
            ->call('saveDetails')
            ->assertHasNoErrors();

        $property->refresh();
        $this->assertSame('Renamed', $property->name);
        $this->assertSame(PropertyType::Condo, $property->property_type);
    }
}
