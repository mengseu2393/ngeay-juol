<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Filament\Resources\UnitResource\Pages\EditUnit;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\RoomDetailsService;
use App\Services\RoomMeterReadingsService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The room "Meter readings" form and the room's own details, moved out of
 * UnitResource / EditUnit into services so /m's Edit room screen saves exactly
 * what the panel does.
 */
class RoomMeterReadingsServiceTest extends MobileTestCase
{
    public function test_it_saves_one_reading_per_meter_utility(): void
    {
        [$landlord, $unit, $electricity, $water, $flat] = $this->fixture();
        $this->actingAs($landlord);

        $saved = app(RoomMeterReadingsService::class)->save($unit, now()->toDateString(), ReadingType::Actual, [
            $electricity->id => '120.5',
            $water->id => '',
            $flat->id => '9', // not a meter utility — ignored
        ]);

        $this->assertSame(1, $saved);
        $this->assertEquals(120.5, UtilityUsage::where('property_utility_id', $electricity->id)->value('new_reading'));
        $this->assertSame(0, UtilityUsage::where('property_utility_id', $water->id)->count());
        $this->assertSame(0, UtilityUsage::where('property_utility_id', $flat->id)->count());
        $this->assertSame($landlord->id, UtilityUsage::where('property_utility_id', $electricity->id)->value('recorded_by_id'));
    }

    public function test_another_propertys_utility_id_is_ignored(): void
    {
        [$landlord, $unit] = $this->fixture();
        $otherProperty = $this->property($landlord, 'Other');
        $foreign = PropertyUtility::create([
            'property_id' => $otherProperty->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 1, 'unit_of_measure' => 'kWh', 'is_active' => true,
        ]);
        $this->actingAs($landlord);

        $this->assertSame(0, app(RoomMeterReadingsService::class)->save($unit, now()->toDateString(), 1, [$foreign->id => '5']));
    }

    public function test_room_details_update_only_touches_room_fields(): void
    {
        [$landlord, $unit] = $this->fixture();
        $this->actingAs($landlord);

        app(RoomDetailsService::class)->update($unit, ['room_number' => 'B-2', 'rent_amount' => 410, 'account_user_id' => 999]);

        $unit->refresh();
        $this->assertSame('B-2', $unit->room_number);
        $this->assertEquals(410, $unit->rent_amount);
        $this->assertNull($unit->account_user_id);
    }

    public function test_a_room_with_a_tenant_cannot_be_deleted(): void
    {
        $landlord = $this->landlord();
        $rental = $this->rental($landlord, $this->property($landlord), 'A-1', 'Dara');
        $this->actingAs($landlord);

        $this->expectException(\DomainException::class);

        try {
            app(RoomDetailsService::class)->delete($rental->unit);
        } finally {
            $this->assertNotNull(Unit::find($rental->unit_id));
        }
    }

    public function test_the_panel_edit_page_still_saves_room_details(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        [$landlord, $unit] = $this->fixture();
        $this->signIn($landlord, $unit->property);

        Livewire::test(EditUnit::class, ['record' => $unit->getKey()])
            ->fillForm(['room_number' => 'C-9', 'rent_amount' => 275])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'room_number' => 'C-9', 'rent_amount' => 275]);
    }

    /** @return array{0: User, 1: Unit, 2: PropertyUtility, 3: PropertyUtility, 4: PropertyUtility} */
    private function fixture(): array
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $make = fn (string $name, BillingType $type, string $uom) => PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => $name,
            'billing_type' => $type, 'rate' => 1, 'unit_of_measure' => $uom, 'is_active' => true,
        ]);

        $unit = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'room_number' => '101', 'room_type' => 'Standard', 'rent_amount' => 300,
        ]);

        return [$landlord, $unit, $make('Electricity', BillingType::Metered, 'kWh'), $make('Water', BillingType::Metered, 'm³'), $make('Trash', BillingType::Flat, 'month')];
    }
}
