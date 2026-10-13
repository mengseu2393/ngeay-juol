<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\EndTenancy;
use App\Models\Rental;
use App\Models\Unit;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * /m/rooms/{unit}/end. Same form and rules as Simple Mode's end-tenancy flow
 * (Concerns\EndsTenancy → EndTenancyService); the room is the one in the URL —
 * even when another property is the active one — and can't be swapped.
 */
class MobileEndTenancyTest extends MobileTestCase
{
    public function test_it_shows_whose_tenancy_ends(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $html = $this->signIn($landlord, $property)
            ->get(route('mobile.room.end-tenancy', $rental->unit_id))
            ->assertOk()
            ->assertSee('Sok Dara')
            ->assertSee('id="rw-m-end-tenancy-form"', false)
            ->assertSee('wire:confirm=', false)
            ->assertSee('href="'.route('mobile.room', $rental->unit_id).'"', false)
            ->getContent();

        $this->assertStringNotContainsString('/app/', $html);
        $this->assertStringNotContainsString('rw-sm-', $html);
    }

    public function test_ending_the_tenancy_frees_the_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $this->signIn($landlord, $property);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->set('endDate', now()->toDateString())
            ->set('status', (string) RentalStatus::Expired->value)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done')
            ->assertSet('result.tenant_name', 'Sok Dara')
            ->assertDispatched('tenancy-ended')
            ->assertSeeHtml('id="rw-m-end-tenancy-done"');

        $this->assertSame(RentalStatus::Expired, $rental->fresh()->status);
        $this->assertSame(UnitStatus::Available, $rental->unit->fresh()->status);
    }

    public function test_the_room_can_stay_unavailable(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $this->signIn($landlord, $property);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->set('freeRoom', false)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(RentalStatus::Vacated, $rental->fresh()->status);
        $this->assertSame(UnitStatus::Occupied, $rental->unit->fresh()->status);
    }

    /** Only Vacated / Expired end an agreement — a forged "Active" or "Terminated" never reaches the rental. */
    public function test_only_an_ending_outcome_is_accepted(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $this->signIn($landlord, $property);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->set('status', (string) RentalStatus::Active->value)
            ->call('submit')
            ->assertHasErrors('status')
            ->assertSet('step', 'confirm')
            ->assertNotDispatched('tenancy-ended');

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->set('status', '99')
            ->call('submit')
            ->assertHasErrors('status');

        $this->assertSame(RentalStatus::Active, $rental->fresh()->status);
    }

    public function test_the_end_date_cannot_be_before_the_start(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $this->signIn($landlord, $property);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->set('endDate', now()->startOfMonth()->subDay()->toDateString())
            ->call('submit')
            ->assertHasErrors('endDate');

        $this->assertSame(RentalStatus::Active, $rental->fresh()->status);
    }

    public function test_a_vacant_room_has_nothing_to_end(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'room_number' => 'A-9',
            'room_type' => 'Standard', 'rent_amount' => 200, 'status' => UnitStatus::Available,
        ]);

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.end-tenancy', $room))
            ->assertOk()
            ->assertSee(__('This room has no active tenancy.'))
            ->assertDontSee('id="rw-m-end-tenancy-form"', false);

        Livewire::test(EndTenancy::class, ['unit' => $room])
            ->call('submit')
            ->assertHasErrors('unitId')
            ->assertNotDispatched('tenancy-ended');
    }

    /** A deep link from another property's context still ends this room's tenancy (not "Room not found"). */
    public function test_it_works_while_another_property_is_active(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');
        $rental = $this->rental($landlord, $other, 'B-1', 'Vanna');

        $this->signIn($landlord, $home);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $this->assertSame(RentalStatus::Vacated, $rental->fresh()->status);
    }

    public function test_once_done_it_cannot_be_submitted_again(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $this->signIn($landlord, $property);

        Livewire::test(EndTenancy::class, ['unit' => $rental->unit])
            ->call('submit')
            ->assertSet('step', 'done')
            ->call('submit')
            ->assertNotFound();
    }

    public function test_another_landlords_room_is_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.end-tenancy', $foreign->unit_id))
            ->assertNotFound();

        $this->assertSame(RentalStatus::Active, Rental::withoutGlobalScopes()->find($foreign->id)->status);
    }

    /** The room is fixed by the URL; a tampered request can't end another landlord's tenancy. */
    public function test_the_room_cannot_be_swapped_from_the_browser(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');
        $stranger = $this->landlord();
        $foreign = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');

        $this->signIn($landlord, $property);

        try {
            Livewire::test(EndTenancy::class, ['unit' => $rental->unit])->set('unitId', $foreign->unit_id);
            $this->fail('The room was swapped.');
        } catch (CannotUpdateLockedPropertyException) {
            // Refused.
        }

        $this->assertSame(RentalStatus::Active, Rental::withoutGlobalScopes()->find($foreign->id)->status);
    }
}
