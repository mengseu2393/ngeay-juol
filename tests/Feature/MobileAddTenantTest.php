<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\AddTenant;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * /m/rooms/{unit}/tenant. Same form and rules as Simple Mode's add-tenant flow
 * (Concerns\StartsTenancy → StartTenancyService); the room is the one in the
 * URL, so these pin that it can't be swapped, that a room that can't take a
 * tenant says so instead of showing the form, and that the photos add up to two
 * instead of the second replacing the first.
 */
class MobileAddTenantTest extends MobileTestCase
{
    private function vacantRoom(User $landlord, Property $property, string $room = 'A-1'): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'rent_amount' => 275,
            'status' => UnitStatus::Available,
        ]);
    }

    public function test_the_form_opens_for_a_vacant_room_with_its_rent(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $html = $this->signIn($landlord, $property)
            ->get(route('mobile.room.add-tenant', $room))
            ->assertOk()
            ->assertSee('id="rw-m-add-tenant-form"', false)
            ->assertSee('id="rw-m-at-photo-input"', false)
            ->assertSee('href="'.route('mobile.room', $room).'"', false)
            ->getContent();

        $this->assertStringNotContainsString('/app/', $html);
        $this->assertStringNotContainsString('rw-sm-', $html);

        Livewire::test(AddTenant::class, ['unit' => $room])
            ->assertSet('unitId', $room->id)
            ->assertSet('monthlyRent', '275')
            ->assertSet('startDate', now()->toDateString());
    }

    public function test_an_occupied_room_says_it_is_not_available(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.add-tenant', $rental->unit_id))
            ->assertOk()
            ->assertSee(__('This room is not available for a new tenant.'))
            ->assertDontSee('id="rw-m-add-tenant-form"', false);

        // A forged submit is refused by the same check.
        Livewire::test(AddTenant::class, ['unit' => $rental->unit])
            ->set('occupantName', 'Intruder')
            ->call('submit')
            ->assertHasErrors('unitId');

        $this->assertDatabaseMissing('rentals', ['occupant_name' => 'Intruder']);
    }

    public function test_moving_a_tenant_in_creates_the_agreement_photos_readings_and_login(): void
    {
        Storage::fake('public');
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $electricity = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 0.15, 'unit_of_measure' => 'kWh',
        ]);

        $this->signIn($landlord, $property);

        $screen = Livewire::test(AddTenant::class, ['unit' => $room])
            ->assertSeeHtml('id="rw-m-at-reading-'.$electricity->id.'"')
            ->set('occupantName', 'Sok Dara')
            ->set('occupantPhone', '012345678')
            ->set('occupantIdCard', 'ID-123')
            ->set('occupantGender', 'female')
            ->set('occupantDob', '1995-06-15')
            ->set('occupantNationality', 'Khmer')
            ->set('occupantWorkplace', 'Factory')
            ->set('occupantAddress', 'Phnom Penh')
            ->set('emergencyContactName', 'Sok Mean')
            ->set('emergencyContactPhone', '098765432')
            ->set('emergencyContactRelationship', 'Sibling')
            ->set('monthlyRent', '300')
            ->set('securityDeposit', '600')
            ->set("moveInReadings.{$electricity->id}", '1500')
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('front.jpg')])
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('back.jpg')])
            ->assertCount('idCardPhotos', 2)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done')
            ->assertDispatched('tenancy-started')
            ->assertSeeHtml('id="rw-m-add-tenant-username"');

        $rental = Rental::where('unit_id', $room->id)->firstOrFail();
        $this->assertSame(RentalStatus::Active, $rental->status);
        $this->assertSame('Sok Dara', $rental->occupant_name);
        $this->assertSame('Sibling', $rental->emergency_contact_relationship);
        $this->assertEquals(600, (float) $rental->security_deposit);
        $this->assertCount(2, $rental->getMedia('id_cards'));
        $this->assertSame(UnitStatus::Occupied, $room->fresh()->status);
        $this->assertEquals(0, (float) UtilityUsage::where('new_reading', 1500)->value('amount_used'));

        $screen->assertSet('result.rental_id', $rental->id)
            ->assertSeeHtml('href="'.route('mobile.tenant', $rental).'"');
    }

    /** The camera takes one photo at a time: each pick adds to the list, never past two. */
    public function test_photos_add_up_to_two(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $room])
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('a.jpg')])
            ->assertCount('idCardPhotos', 1)
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')])
            ->assertCount('idCardPhotos', 2)
            ->assertCount('newIdCardPhotos', 0)
            ->assertDontSeeHtml('id="rw-m-at-photo-add"')
            ->call('removeIdCardPhoto', 0)
            ->assertCount('idCardPhotos', 1)
            ->assertSeeHtml('id="rw-m-at-photo-add"');
    }

    public function test_a_non_image_is_rejected(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $room])
            ->set('newIdCardPhotos', [UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')])
            ->assertHasErrors('newIdCardPhotos.0')
            ->assertCount('idCardPhotos', 0);
    }

    public function test_the_name_and_rent_are_required(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $room])
            ->set('monthlyRent', '')
            ->call('submit')
            ->assertHasErrors(['occupantName', 'monthlyRent'])
            ->assertSet('step', 'details')
            ->assertNotDispatched('tenancy-started');

        $this->assertSame(0, Rental::count());
    }

    /** Let from another phone while this form was open: the second move-in is refused. */
    public function test_a_room_let_in_the_meantime_is_refused(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        $screen = Livewire::test(AddTenant::class, ['unit' => $room])->set('occupantName', 'Second');

        $tenant = User::factory()->create(['email' => 'first-'.uniqid().'@example.com']);
        Rental::create([
            'landlord_id' => $landlord->id, 'unit_id' => $room->id, 'tenant_id' => $tenant->id, 'occupant_name' => 'First',
            'monthly_rent' => 275, 'status' => RentalStatus::Active, 'start_date' => now()->toDateString(),
        ]);

        $screen->call('submit')->assertHasErrors('unitId');

        $this->assertDatabaseMissing('rentals', ['occupant_name' => 'Second']);
    }

    public function test_once_done_the_form_cannot_be_submitted_again(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $room])
            ->set('occupantName', 'Sok Dara')
            ->call('submit')
            ->assertSet('step', 'done')
            ->call('submit')
            ->assertNotFound();

        $this->assertSame(1, Rental::count());
    }

    public function test_another_landlords_room_is_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger));

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.add-tenant', $foreign))
            ->assertNotFound();
    }

    /** The room is fixed by the URL; a tampered request can't point the move-in at another landlord's room. */
    public function test_the_room_cannot_be_swapped_from_the_browser(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger));

        $this->signIn($landlord, $property);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(AddTenant::class, ['unit' => $room])->set('unitId', $foreign->id);
    }
}
