<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\RentalStatus;
use App\Livewire\M2\CreateInvoice;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Livewire\Livewire;

/**
 * /m2's per-room "Create invoice" (the Room screen's button). The billing maths
 * — period, proration, readings, charge rules, duplicate guard, the
 * transaction — is Concerns\BuildsMonthlyBilling's and has its own tests; pinned
 * here is that it bills exactly this room's tenancy, refuses to double-bill,
 * and can't be pointed at another landlord's tenancy.
 */
class M2CreateInvoiceTest extends M2TestCase
{
    public function test_the_room_offers_the_button_only_when_there_is_a_tenant(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $let = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $empty = $this->rental($landlord, $property, 'A-2', 'Sokha')->unit;
        $empty->activeRental->update(['status' => RentalStatus::Vacated]);

        $this->signIn($landlord, $property);

        $this->get(route('m2.room', $let))->assertSee('id="m2-room-create-invoice"', false)->assertSee(route('m2.invoice.create', $let), false);
        $this->get(route('m2.room', $empty->fresh()))->assertDontSee('id="m2-room-create-invoice"', false);
    }

    public function test_it_opens_on_this_rooms_tenancy_only(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        Livewire::test(CreateInvoice::class, ['unit' => $mine->unit])
            ->assertSet('step', 'reading')
            ->assertSet('manualMode', true)
            ->assertCount('rooms', 1)
            ->assertSet('rooms.0.rental_id', $mine->id)
            ->assertSee('Dara')
            ->assertDontSee('Sokha');
    }

    public function test_it_creates_one_invoice_for_the_room_and_links_to_its_slip(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');
        $neighbour = $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        $component = Livewire::test(CreateInvoice::class, ['unit' => $mine->unit])
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('openCreateConfirmation')
            ->assertSet('showCreateConfirmation', true)
            ->call('createInvoices')
            ->assertSet('step', 'result')
            ->assertSet('resultSummary.created', 1)
            ->assertSeeHtml('id="m2-invoice-open-slip"');

        $invoice = Invoice::where('rental_id', $mine->id)->sole();
        $component->assertSee(route('m2.slip', $invoice), false);
        $this->assertTrue($invoice->lines()->exists());
        $this->assertSame(0, Invoice::where('rental_id', $neighbour->id)->count());
    }

    public function test_it_will_not_create_before_every_reading_is_in(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(CreateInvoice::class, ['unit' => $mine->unit])
            ->assertSeeHtml('id="m2-invoice-create"')
            ->call('openCreateConfirmation')
            ->assertSet('showCreateConfirmation', false)
            ->call('createInvoices')
            ->assertSet('step', 'reading');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_second_invoice_for_the_same_period_is_never_created(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(CreateInvoice::class, ['unit' => $mine->unit])
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices')
            ->assertSet('resultSummary.created', 1);

        // Billing resumes the day after the last invoice's period, which is still in the future of
        // today's billing date — an empty period is blocked, never billed a second time.
        Livewire::test(CreateInvoice::class, ['unit' => $mine->unit->fresh()])
            ->assertSee(__('Period start cannot be after period end'))
            ->call('openCreateConfirmation')
            ->assertSet('showCreateConfirmation', false)
            ->call('createInvoices')
            ->assertSet('resultSummary.created', 0);

        $this->assertSame(1, Invoice::where('rental_id', $mine->id)->count());
    }

    public function test_a_vacant_room_and_a_property_with_billing_off_say_so(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $vacant = $this->rental($landlord, $property, 'A-1', 'Dara');
        $vacant->update(['status' => RentalStatus::Vacated]);
        $off = $this->billingProperty($landlord, 'Billing off', enabled: false);
        $offRental = $this->rental($landlord, $off, 'B-1', 'Bopha');

        $this->signIn($landlord, $property);

        Livewire::test(CreateInvoice::class, ['unit' => $vacant->unit->fresh()])->assertSet('step', 'vacant')->assertSeeHtml('id="m2-invoice-vacant"');
        Livewire::test(CreateInvoice::class, ['unit' => $offRental->unit])->assertSet('step', 'blocked')->assertSeeHtml('id="m2-invoice-blocked"');
    }

    public function test_another_landlords_room_is_not_found_and_a_tampered_rental_is_refused(): void
    {
        $mine = $this->landlord();
        $property = $this->billingProperty($mine);
        $own = $this->rental($mine, $property, 'A-1', 'Dara');

        $stranger = $this->landlord();
        $foreign = $this->rental($stranger, $this->billingProperty($stranger, 'Theirs'), 'Z-1', 'Zed');

        $this->signIn($mine, $property);

        $this->get(route('m2.invoice.create', $foreign->unit_id))->assertNotFound();

        Livewire::test(CreateInvoice::class, ['unit' => $own->unit])
            ->set('rooms.0.rental_id', $foreign->id)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices')
            ->assertForbidden();

        $this->assertSame(0, Invoice::count());
    }

    protected function billingProperty(User $landlord, string $name = 'Billing Property', bool $enabled = true): Property
    {
        $property = $this->property($landlord, $name);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'monthly_billing_enabled' => $enabled,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
        ]);

        $this->utility($property, 'Electricity', BillingType::Metered, 0.25);

        return $property;
    }
}
