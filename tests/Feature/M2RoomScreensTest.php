<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\Bill;
use App\Livewire\M2\Room;
use App\Livewire\M2\Slip;
use App\Livewire\M2\Tenant;
use App\Models\ChargeRule;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Unit;
use App\Services\InvoicePdfService;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * /m2's room-side screens — Room, Tenant, Service, Bill, Slip (sketches ②, ④,
 * ⑤ and the slip). Pinned here is what each one shows, and that a flat charge and
 * a meter can't be opened on each other's screen; the writes have their own test
 * classes (M2BillPaymentTest, M2EditRoomTest, M2EditTenantTest, M2TenantEndTest).
 */
class M2RoomScreensTest extends M2TestCase
{
    public function test_the_room_shows_tenant_meters_price_services_and_latest_bill(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update(['occupant_phone' => '012 345 678']);
        $unit = $rental->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->utility($property, 'Water');
        $this->utility($property, 'Parking', BillingType::Flat, 5);
        $this->reading($landlord, $unit, $electricity, '2026-10-10', 1250, 40);
        $this->invoice($rental, 'INV-9', 300);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $unit])
            ->assertSeeHtml('id="m2-room-tenant"')
            ->assertSee('Dara')
            ->assertSee('012 345 678')
            ->assertSeeHtml('id="m2-meter-'.$electricity->id.'"')
            ->assertSee('1,250')
            ->assertSee(__('Used').' 40 kWh')
            ->assertSee('$300.00')
            ->assertSee(__('Parking'))
            ->assertSee('$5.00')
            ->assertSeeHtml('id="m2-room-bill"');
    }

    public function test_a_vacant_room_says_so_and_has_no_tenant_row(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $unit->activeRental->update(['status' => RentalStatus::Vacated]);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $unit->fresh()])
            ->assertSeeHtml('id="m2-room-vacant"')
            ->assertDontSeeHtml('id="m2-room-tenant"')
            ->assertSee(__('No bill yet'));
    }

    public function test_opening_a_room_of_another_property_switches_to_that_property(): void
    {
        $landlord = $this->landlord();
        $first = $this->property($landlord, 'First');
        $second = $this->property($landlord, 'Second');
        $unit = $this->rental($landlord, $second, 'B-1', 'Bopha')->unit;

        $this->signIn($landlord, $first);

        Livewire::test(Room::class, ['unit' => $unit]);

        $this->assertSame($second->id, ActiveProperty::id());
    }

    public function test_the_tenant_screen_shows_contact_and_id_card_details(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update([
            'occupant_phone' => '099 111 222',
            'occupant_gender' => 'female',
            'occupant_dob' => '1995-04-02',
            'occupant_id_card' => '010203040',
            'occupant_address' => 'Phnom Penh',
        ]);

        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSee('Dara')
            ->assertSee('099 111 222')
            ->assertSee(__('Female'))
            ->assertSee(Carbon::parse('1995-04-02')->translatedFormat('d M Y'))
            ->assertSee('010203040')
            ->assertSee('Phnom Penh')
            ->assertSee(__('No ID card photo uploaded.'));
    }

    /**
     * "Added to" is read from the same charge rules the invoice builder resolves, not
     * written as fixed text: a plain flat charge is on every invoice, a room-level
     * "not applicable" rule means this room is never charged, and a vacant room has no
     * invoices so the row is left out.
     */
    public function test_the_service_screen_says_where_the_charge_lands_for_this_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $parking = $this->utility($property, 'Parking', BillingType::Flat, 5);
        $lift = $this->utility($property, 'Lift', BillingType::Flat, 2);
        $vacant = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $vacant->update(['status' => RentalStatus::Vacated]);

        ChargeRule::create([
            'property_utility_id' => $lift->id,
            'property_id' => $property->id,
            'scope_type' => 'unit',
            'scope_id' => $unit->id,
            'state' => 'not_applicable',
        ]);

        $this->signIn($landlord, $property);

        $this->get(route('m2.service', [$unit, $parking]))
            ->assertSee('id="m2-service-added"', false)
            ->assertSee(__('Every invoice'));
        $this->get(route('m2.service', [$unit, $lift]))
            ->assertSee(__('Not charged to this room'))
            ->assertDontSee(__('Every invoice'));
        $this->get(route('m2.service', [$vacant->unit->fresh(), $parking]))
            ->assertOk()
            ->assertDontSee('id="m2-service-added"', false);
    }

    public function test_a_flat_charge_opens_on_the_service_screen_and_a_meter_does_not(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $parking = $this->utility($property, 'Parking', BillingType::Flat, 5);
        $lift = $this->utility($property, 'Lift', BillingType::Flat, 2);
        $electricity = $this->utility($property, 'Electricity');

        $this->signIn($landlord, $property);

        $this->get(route('m2.service', [$unit, $parking]))
            ->assertOk()
            ->assertSee('$5.00')
            ->assertSee('Lift')
            ->assertSee('Dara');
        $this->get(route('m2.service', [$unit, $electricity]))->assertNotFound();
        $this->get(route('m2.meter', [$unit, $lift]))->assertNotFound();
    }

    public function test_the_bill_lists_each_charge_with_the_total_and_can_pick_an_earlier_invoice(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $old = $this->invoice($rental, 'INV-OLD', 300, issued: Carbon::parse('2026-09-05'));
        $new = $this->invoice($rental, 'INV-NEW', 300, issued: Carbon::parse('2026-10-05'));
        $this->line($new, 'Room rent', 200);
        $this->line($new, 'Water usage', 15);
        $this->line($old, 'Room rent', 180);

        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->assertSee('INV-NEW')
            ->assertSee('$200.00')
            ->assertSee('$15.00')
            ->assertSeeHtml('id="m2-bill-total"')
            ->assertSee('$215.00')
            ->call('pick', $old->id)
            ->assertSee('INV-OLD')
            ->assertSee('$180.00')
            ->call('pick', 999999)
            ->assertSee('INV-NEW');
    }

    public function test_the_slip_names_property_room_tenant_and_total(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Son Sok Place');
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-77');
        $this->line($invoice, 'Room rent', 250);

        $this->signIn($landlord, $property);

        Livewire::test(Slip::class, ['invoice' => $invoice])
            ->assertSee('Son Sok Place')
            ->assertSee('A-1')
            ->assertSee('Dara')
            ->assertSee('INV-77')
            ->assertSee('$250.00');
    }

    /**
     * The button has to open the Telegram app itself, so it's a tg:// link and not the phone's share sheet.
     * That link can't carry a file, so the same tap saves the PDF for attaching in the chat.
     */
    public function test_the_slip_opens_telegram_with_what_is_owed_and_saves_the_pdf(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-77', 250);
        $this->signIn($landlord, $property);

        $message = __('Invoice :number — :room — :amount due :date', [
            'number' => 'INV-77', 'room' => 'A-1', 'amount' => '$250.00', 'date' => Invoice::displayDate($invoice->due_date, 'd M Y'),
        ]);

        Livewire::test(Slip::class, ['invoice' => $invoice])
            ->assertSeeHtml('id="m2-slip-share"')
            ->assertSeeHtml('href="tg://msg?text='.rawurlencode($message).'"')
            ->assertSeeHtml('onclick="m2SaveInvoicePdf(this)"')
            ->assertSeeHtml('data-pdf-url="'.route('invoices.pdf', ['invoice' => $invoice->id]).'"')
            ->assertSeeHtml('data-filename="'.InvoicePdfService::filename($invoice, 'pdf').'"');

        // The saving script comes with the layout: without it the tap would open Telegram with no PDF.
        $this->get(route('m2.slip', $invoice))->assertOk()->assertSee('window.m2SaveInvoicePdf', false);
    }

    /** "Pay $0.00 by…" would send a tenant who has paid the wrong message. */
    public function test_a_paid_bill_is_shared_as_paid(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-77', 250);
        $this->signIn($landlord, $property);
        $invoice->recordPayment(['amount' => 250, 'currency' => 'USD']);

        Livewire::test(Slip::class, ['invoice' => $invoice->fresh()])
            ->assertSeeHtml('id="m2-slip-share"')
            ->assertSeeHtml('href="tg://msg?text='.rawurlencode(__('Invoice :number — :room — paid', ['number' => 'INV-77', 'room' => 'A-1'])).'"');
    }

    /** A tenant would read a cancelled bill as still owed. */
    public function test_a_cancelled_bill_cannot_be_shared(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-77', 250, InvoiceStatus::Cancelled);
        $this->signIn($landlord, $property);

        Livewire::test(Slip::class, ['invoice' => $invoice])
            ->assertDontSeeHtml('id="m2-slip-share"')
            ->assertSeeHtml('id="m2-slip-pdf"');
    }

    private function line($invoice, string $description, float $amount): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceLineType::Rent,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'currency' => 'USD',
            'unit_price_currency' => 'USD',
            'amount_usd' => $amount,
            'amount_khr' => $amount * 4000,
            'unit_price_usd' => $amount,
            'unit_price_khr' => $amount * 4000,
            'exchange_rate' => 4000,
        ]);
    }

    /**
     * Why: a room set to Maintenance/Unavailable can't take a tenant, and /m2 had
     * no way to flip it back, so the landlord was stuck with no "Add tenant" button.
     */
    public function test_an_unavailable_vacant_room_can_be_marked_available_then_gets_add_tenant(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'C-302',
            'room_type' => 'Standard',
            'rent_amount' => 100,
            'status' => UnitStatus::Unavailable,
        ]);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $unit])
            ->assertSee(__('Mark room as available'))
            ->assertDontSee(__('Add tenant'))
            ->call('markAvailable')
            ->assertDispatched('rw-m-toast', message: __('Room is available for a new tenant.'))
            ->assertSee(__('Add tenant'));

        $this->assertSame(UnitStatus::Available, $unit->fresh()->status);
    }
}
