<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\Rooms;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Js;
use Livewire\Livewire;

/**
 * /m/rooms. It shares its list, price edit and first-reading logic with Simple
 * Mode's Rooms screen (Concerns\ManagesRoomList); these pin that the phone
 * screen applies it — every room of the active property only, filtered in the
 * browser, every write scoped to the landlord — and that it never leads back
 * into the Filament panel.
 */
class MobileRoomsTest extends MobileTestCase
{
    private function vacantRoom(User $landlord, Property $property, string $room, float $rent = 250): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'rent_amount' => $rent,
            'status' => UnitStatus::Available,
        ]);
    }

    private function meteredUtility(User $landlord, Property $property, string $name = 'Electricity'): PropertyUtility
    {
        return PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => $name,
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);
    }

    /** The card's x-data payload, exactly as @js renders one key => value. */
    private function cardJson(string $key, mixed $value): string
    {
        return substr(Js::from([$key => $value])->toHtml(), strlen("JSON.parse('{"), -strlen("}')"));
    }

    /**
     * Pills and search run in the browser, so the server must always render every
     * room and stamp each card with the status and text the client matches on.
     */
    public function test_it_renders_every_room_of_the_active_property_with_filter_attributes(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');
        $vacant = $this->vacantRoom($landlord, $home, 'A-1');
        $occupied = $this->rental($landlord, $home, 'A-2', 'Dara');
        $this->vacantRoom($landlord, $other, 'B-9');

        $html = $this->signIn($landlord, $home)
            ->get(route('mobile.rooms'))
            ->assertOk()
            ->assertSee('A-1')
            ->assertSee('A-2')
            ->assertSee('Dara')
            ->assertDontSee('B-9')
            ->assertSee('data-status="available"', false)
            ->assertSee('data-status="occupied"', false)
            ->assertSee('data-q="a-2 dara ', false)
            ->assertSee('href="'.route('mobile.room', $vacant).'"', false)
            ->getContent();

        $this->assertStringContainsString('href="'.route('mobile.room', $occupied->unit_id).'"', $html);
        // No way back into Filament from /m.
        $this->assertStringNotContainsString('/app/', $html);
        $this->assertStringNotContainsString('rw-sm-', $html);
    }

    public function test_another_landlords_rooms_never_show(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $this->vacantRoom($stranger, $this->property($stranger), 'X-STRANGER');

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->assertDontSee('X-STRANGER')
            ->assertSeeHtml('id="rw-m-rooms-empty"');
    }

    /** A deep link only seeds the active pill; it never hides rooms server-side. */
    public function test_a_filter_deep_link_seeds_the_pill_and_still_renders_every_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->vacantRoom($landlord, $property, 'A-1');
        $this->rental($landlord, $property, 'A-2', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::withQueryParams(['filter' => 'occupied'])
            ->test(Rooms::class)
            ->assertSet('filter', 'occupied')
            ->assertSee('A-1')->assertSee('A-2');

        Livewire::withQueryParams(['filter' => 'nonsense'])
            ->test(Rooms::class)
            ->assertSet('filter', 'all');
    }

    /**
     * A vacant room offers Add tenant, an occupied one its tenant (on the Tenant
     * screen) and End tenancy — all /m screens.
     */
    public function test_each_card_links_to_the_mobile_room_screens(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $vacant = $this->vacantRoom($landlord, $property, 'A-1');
        $rental = $this->rental($landlord, $property, 'A-2', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->assertSeeHtml('id="rw-m-room-add-tenant-'.$vacant->id.'"')
            ->assertSeeHtml('id="rw-m-room-view-tenant-'.$rental->unit_id.'"')
            ->assertDontSeeHtml('id="rw-m-room-add-tenant-'.$rental->unit_id.'"')
            ->assertSeeHtml($this->cardJson('addUrl', route('mobile.room.add-tenant', $vacant)))
            ->assertSeeHtml($this->cardJson('endUrl', route('mobile.room.end-tenancy', $rental->unit_id)))
            ->assertSeeHtml($this->cardJson('tenantUrl', route('mobile.tenant', $rental)));
    }

    public function test_editing_the_price_updates_the_room_and_its_tenancy(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->call('submitEditPriceFor', $rental->unit_id, '355.50')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'room-price')
            ->assertDispatched('rw-m-toast', message: __('Room price saved.'));

        $this->assertEquals(355.50, (float) $rental->unit->fresh()->rent_amount);
        $this->assertEquals(355.50, (float) $rental->fresh()->monthly_rent);
    }

    public function test_an_invalid_price_keeps_the_sheet_open_with_an_error(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property, 'A-1', 250);

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->call('submitEditPriceFor', $room->id, '-5')
            ->assertHasErrors('priceValue')
            ->assertNotDispatched('rw-m-toast');

        $this->assertEquals(250, (float) $room->fresh()->rent_amount);
    }

    public function test_another_landlords_room_price_cannot_be_changed(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger), 'X-1', 250);

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->call('submitEditPriceFor', $foreign->id, '1')
            ->assertNotFound();

        $this->assertEquals(250, (float) Unit::withoutGlobalScopes()->find($foreign->id)->rent_amount);
    }

    /**
     * The first reading only stamps a starting point (no previous, nothing used);
     * a blank field is skipped and a key for another property's utility ignored.
     */
    public function test_the_first_meter_reading_records_a_baseline(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property, 'A-1');
        $electricity = $this->meteredUtility($landlord, $property);
        $water = $this->meteredUtility($landlord, $property, 'Water');
        $elsewhere = $this->meteredUtility($landlord, $this->property($landlord, 'Other'));

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->assertSeeHtml('id="rw-m-room-set-reading-'.$room->id.'"')
            ->call('submitUtilityReadingFor', $room->id, [$electricity->id => '1200', $water->id => '', $elsewhere->id => '5'])
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'room-reading')
            ->assertDispatched('rw-m-toast', message: __('Utility reading saved.'))
            // The room has a reading now, so the card stops asking for one.
            ->assertDontSeeHtml('id="rw-m-room-set-reading-'.$room->id.'"');

        $this->assertSame(1, UtilityUsage::count());
        $this->assertDatabaseHas('utility_usages', [
            'unit_id' => $room->id,
            'property_utility_id' => $electricity->id,
            'old_reading' => null,
            'new_reading' => 1200,
            'amount_used' => 0,
        ]);
    }

    public function test_a_first_reading_needs_at_least_one_value(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property, 'A-1');
        $electricity = $this->meteredUtility($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(Rooms::class)
            ->call('submitUtilityReadingFor', $room->id, [$electricity->id => ''])
            ->assertHasErrors('readingValues')
            ->assertNotDispatched('rw-m-toast');

        $this->assertSame(0, UtilityUsage::count());
    }

    public function test_another_landlords_room_gets_no_reading(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meteredUtility($landlord, $property);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger), 'X-1');

        $this->signIn($landlord, $property);

        try {
            Livewire::test(Rooms::class)->call('submitUtilityReadingFor', $foreign->id, [$electricity->id => '10']);
            $this->fail('Another landlord\'s room was found.');
        } catch (ModelNotFoundException) {
            // A 404 over HTTP.
        }

        $this->assertSame(0, UtilityUsage::withoutGlobalScopes()->count());
    }

    public function test_without_an_active_property_it_asks_for_one(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'One');
        $this->property($landlord, 'Two');

        // Explicitly "All properties".
        $this->actingAs($landlord)->withSession([ActiveProperty::SESSION_KEY => 0]);

        $this->get(route('mobile.rooms'))
            ->assertOk()
            ->assertSee(__('Choose a property first'));
    }
}
