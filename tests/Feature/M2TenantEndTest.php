<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\Home;
use App\Livewire\M2\Tenant;
use Livewire\Livewire;

/**
 * /m2 Tenant — "End tenancy". It runs EndTenancyService (same rules as the /m shell and Simple Mode),
 * only for the room's current tenant, and the room goes back to Available unless told otherwise.
 */
class M2TenantEndTest extends M2TestCase
{
    public function test_ending_a_tenancy_vacates_the_rental_and_frees_the_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSeeHtml('id="m2-tenant-end"')
            ->assertDontSeeHtml('id="m2-tenant-end-form"')
            ->call('openEnd')
            ->assertSeeHtml('id="m2-tenant-end-form"')
            ->set('endDate', '2026-10-13')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done')
            ->assertSeeHtml('id="m2-tenant-ended"')
            ->assertDontSeeHtml('id="m2-tenant-end"');

        $this->assertSame(RentalStatus::Vacated, $rental->fresh()->status);
        $this->assertSame('2026-10-13', $rental->fresh()->end_date->toDateString());
        $this->assertSame(UnitStatus::Available, $rental->unit->fresh()->status);
    }

    public function test_the_room_can_stay_occupied_and_the_outcome_can_be_expired(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->call('openEnd')
            ->set('status', (string) RentalStatus::Expired->value)
            ->set('freeRoom', false)
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertSame(RentalStatus::Expired, $rental->fresh()->status);
        $this->assertNotSame(UnitStatus::Available, $rental->unit->fresh()->status);
    }

    public function test_an_end_date_before_the_start_date_is_refused_and_nothing_changes(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->call('openEnd')
            ->set('endDate', '1999-01-01')
            ->call('submit')
            ->assertHasErrors(['endDate'])
            ->assertSet('step', 'view');

        $this->assertSame(RentalStatus::Active, $rental->fresh()->status);
    }

    public function test_an_unknown_outcome_is_a_validation_error_not_a_crash(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->call('openEnd')
            ->set('status', '99')
            ->call('submit')
            ->assertHasErrors(['status']);

        $this->assertSame(RentalStatus::Active, $rental->fresh()->status);
    }

    public function test_an_ended_tenancy_has_no_end_button_and_cannot_be_ended_again(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update(['status' => RentalStatus::Vacated, 'end_date' => '2026-10-01']);
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertDontSeeHtml('id="m2-tenant-end"')
            ->call('openEnd')
            ->assertForbidden();
    }

    public function test_the_properties_side_shows_how_many_rooms_are_occupied(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Sunrise');
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');
        $this->signIn($landlord, $property);

        Livewire::test(Home::class, ['view' => 'properties'])
            ->assertSee('Sunrise')
            ->assertSee(__(':rooms rooms · :occupied occupied', ['rooms' => 2, 'occupied' => 2]));
    }
}
