<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\Tenants;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Livewire\Livewire;

/**
 * /m/tenants. It shares its list logic with Simple Mode's Tenants screen
 * (Concerns\ListsTenancies); these pin that the phone screen applies it —
 * scoped to the active property and the signed-in landlord, filtered
 * server-side — and links each card to the tenant's own /m screen.
 */
class MobileTenantsTest extends MobileTestCase
{
    public function test_it_lists_the_active_propertys_active_tenancies_by_default(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');

        $dara = $this->rental($landlord, $home, 'A-1', 'Dara');
        $ended = $this->rental($landlord, $home, 'A-2', 'Sokha Ended');
        $ended->update(['status' => RentalStatus::Vacated]);
        $this->rental($landlord, $other, 'B-1', 'Vanna Elsewhere');

        $this->signIn($landlord, $home)
            ->get('/m/tenants')
            ->assertOk()
            ->assertSee('Dara')
            ->assertSee(route('mobile.tenant', $dara))
            ->assertDontSee('Sokha Ended')
            ->assertDontSee('Vanna Elsewhere');
    }

    public function test_another_landlords_tenants_never_show(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Mine');

        $stranger = $this->landlord();
        $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger Tenant');

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)
            ->set('filter', 'all')
            ->assertSee('Mine')
            ->assertDontSee('Stranger Tenant');
    }

    /** The filters are server-side, exactly like Simple Mode's: ended rows aren't rendered until asked for. */
    public function test_filters_narrow_the_list_server_side(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);

        $owing = $this->rental($landlord, $property, 'A-1', 'Owing Tenant');
        $this->invoice($owing, 'INV-1', 120);
        $paid = $this->rental($landlord, $property, 'A-2', 'Paid Tenant');
        $this->invoice($paid, 'INV-2', status: InvoiceStatus::Paid);
        $ended = $this->rental($landlord, $property, 'A-3', 'Ended Tenant');
        $ended->update(['status' => RentalStatus::Expired]);

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)
            ->assertSee('Owing Tenant')->assertSee('Paid Tenant')->assertDontSee('Ended Tenant')
            ->set('filter', 'due')
            ->assertSee('Owing Tenant')->assertDontSee('Paid Tenant')->assertDontSee('Ended Tenant')
            ->set('filter', 'ended')
            ->assertSee('Ended Tenant')->assertDontSee('Owing Tenant')->assertDontSee('Paid Tenant')
            ->set('filter', 'all')
            ->assertSee('Owing Tenant')->assertSee('Paid Tenant')->assertSee('Ended Tenant');
    }

    public function test_the_filter_is_kept_in_the_url(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $ended = $this->rental($landlord, $property, 'A-3', 'Ended Tenant');
        $ended->update(['status' => RentalStatus::Vacated]);

        $this->signIn($landlord, $property)
            ->get('/m/tenants?filter=ended')
            ->assertOk()
            ->assertSee('Ended Tenant');
    }

    public function test_card_shows_balance_due_room_and_a_call_link(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-7', 'Dara');
        $rental->update(['occupant_phone' => '012 345 678']);
        $this->invoice($rental, 'INV-1', 125.5);

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)
            ->assertSee('A-7')
            ->assertSee('012 345 678')
            ->assertSee('href="tel:012345678"', false)
            ->assertSee('$125.50');
    }

    /** Search is client-side; the card carries the text it matches on, incl. a digits-only phone. */
    public function test_cards_carry_search_text_for_name_phone_room_and_co_occupants(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'B-12', 'Sok Dara');
        $rental->update(['occupant_phone' => '012 345 678']);
        $rental->occupants()->create(['role' => 'co_tenant', 'occupant_name' => 'Chan Vanna']);

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)
            ->assertSee('data-q="sok dara 012 345 678', false)
            ->assertSee('b-12', false)
            ->assertSee('chan vanna', false)
            ->assertSee('012345678', false)
            ->assertSee('Chan Vanna'); // "With: Chan Vanna" on the card
    }

    public function test_empty_state_without_tenants(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)->assertSee(__('No tenants found.'));
    }

    /** Adding a tenant is per room on /m, so the button lists vacant rooms linking to that screen. */
    public function test_add_tenant_sheet_lists_only_this_propertys_vacant_rooms(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'OCC-1', 'Dara');
        $vacant = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'FREE-1',
            'room_type' => 'Standard',
            'rent_amount' => 150,
            'status' => UnitStatus::Available,
        ]);
        $elsewhere = $this->property($landlord, 'Elsewhere');
        $otherVacant = Unit::create([
            'property_id' => $elsewhere->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'FAR-1',
            'room_type' => 'Standard',
            'rent_amount' => 150,
            'status' => UnitStatus::Available,
        ]);
        $stranger = $this->landlord();
        $strangerVacant = Unit::create([
            'property_id' => $this->property($stranger)->id,
            'landlord_id' => $stranger->id,
            'room_number' => 'STRANGER-1',
            'room_type' => 'Standard',
            'rent_amount' => 150,
            'status' => UnitStatus::Available,
        ]);

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)
            ->assertSee(route('mobile.room.add-tenant', $vacant))
            ->assertSee('FREE-1')
            ->assertDontSee(route('mobile.room.add-tenant', $otherVacant))
            ->assertDontSee(route('mobile.room.add-tenant', $strangerVacant))
            ->assertDontSee('STRANGER-1');
    }

    public function test_add_tenant_sheet_says_when_no_room_is_vacant(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'OCC-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Tenants::class)->assertSee(__('No vacant rooms'));
    }

    public function test_list_refreshes_on_tenancy_and_billing_events(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);

        $this->signIn($landlord, $property);

        $component = Livewire::test(Tenants::class)->assertDontSee('Late Arrival');

        $rental = $this->rental($landlord, $property, 'A-1', 'Late Arrival');
        $component->dispatch('tenancy-started')->assertSee('Late Arrival');

        $this->invoice($rental, 'INV-NEW', 77);
        $component->dispatch('invoices-created')->assertSee('$77.00');

        $rental->update(['occupant_name' => 'Renamed Tenant']);
        $component->dispatch('tenant-updated')->assertSee('Renamed Tenant');

        $rental->invoices()->update(['payment_status' => InvoiceStatus::Paid]);
        $component->dispatch('pay-saved')->assertDontSee('$77.00');

        $rental->update(['status' => RentalStatus::Vacated]);
        $component->dispatch('tenancy-ended')->assertDontSee('Renamed Tenant');
    }

    public function test_without_a_property_it_asks_to_choose_one(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'One');
        $this->property($landlord, 'Two');

        $this->signIn($landlord);
        ActiveProperty::clear();

        Livewire::test(Tenants::class)->assertSee(__('Choose a property first'));
    }
}
