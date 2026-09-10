<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Livewire\SimpleBillingInvoice;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\LandlordOwnershipGuard;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cross-tenant security boundary for the invoice write paths.
 *
 * Every billing writer has to load its parent rental/usage with
 * `withoutGlobalScopes()` — that is the row which supplies `landlord_id` and
 * `property_id`, so the scope cannot be in force while reading it. The ids fed
 * to those lookups, however, are client-controlled:
 *
 *  - `MonthlyBilling::$rooms` and `SimpleBillingInvoice::$rooms` are public
 *    Livewire array properties, round-tripped to the browser and writable via a
 *    normal `updates` payload (`rooms.0.rental_id`); the snapshot checksum
 *    signs the state, it does not forbid legitimate updates to it.
 *  - `rental_id` and `readings.*.utility_usage_id` on the Invoice resource form
 *    are `Hidden` fields with no validation rule at all. The only scoped Select
 *    is `unit_id`, which the writer never reads, and Filament emits an `exists`
 *    rule only for `->relationship()` selects, not `->options()` ones.
 *
 * Without an ownership assertion, an authenticated landlord could therefore
 * invoice another landlord's rental — and `InvoiceBuilderService` copies
 * `$rental->landlord_id` straight onto the new Invoice, so the row lands in the
 * victim's books — or overwrite another landlord's meter readings.
 *
 * These tests pin {@see LandlordOwnershipGuard} into every one of
 * those paths. They are security regressions, not feature tests: if one starts
 * failing, a tenant-isolation hole has been reopened.
 */
class CrossTenantBillingGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        Carbon::setTestNow('2026-07-05 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- MonthlyBilling ------------------------------------------------------

    /**
     * Landlord A drives the MonthlyBilling page for their own property, then
     * rewrites `rooms.0.rental_id` to point at landlord B's rental before
     * pressing "Create invoices" — exactly what a crafted Livewire `updates`
     * payload does. The run must be refused outright, and B's books untouched.
     */
    public function test_monthly_billing_rejects_a_rental_belonging_to_another_landlord(): void
    {
        [$landlordA, $propertyA] = $this->createLandlordWithRoom('A');
        [, , $rentalB] = $this->createLandlordWithRoom('B');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->set('rooms.0.rental_id', $rentalB->id)
            ->call('createInvoices')
            ->assertForbidden();

        $this->assertDatabaseMissing('invoices', ['rental_id' => $rentalB->id]);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    /**
     * The guard must not cost landlord A the ability to bill their own rooms —
     * the counterpart assertion, so a future "fix" cannot pass by denying
     * everything.
     */
    public function test_monthly_billing_still_bills_the_acting_landlords_own_rental(): void
    {
        [$landlordA, $propertyA, $rentalA] = $this->createLandlordWithRoom('A');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        $test = Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices');

        $this->assertSame(1, $test->instance()->lastRun['created']);
        $this->assertSame(0, $test->instance()->lastRun['failed']);
        $this->assertDatabaseHas('invoices', [
            'rental_id' => $rentalA->id,
            'landlord_id' => $landlordA->id,
        ]);
    }

    // --- SimpleBillingInvoice (Simple Mode) ----------------------------------

    /**
     * Same forged-`rental_id` attack against the Simple Mode wizard, whose
     * `$rooms` array is likewise a public Livewire property.
     */
    public function test_simple_billing_rejects_a_rental_belonging_to_another_landlord(): void
    {
        [$landlordA, $propertyA] = $this->createLandlordWithRoom('A');
        [, , $rentalB] = $this->createLandlordWithRoom('B');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(SimpleBillingInvoice::class)
            ->set('propertyId', $propertyA->id)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->set('rooms.0.rental_id', $rentalB->id)
            ->call('createInvoices')
            ->assertForbidden();

        $this->assertDatabaseMissing('invoices', ['rental_id' => $rentalB->id]);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    /**
     * `recalculateRoomRent()` is reachable on its own through the
     * `updatedRooms` hook (changing a period), and also reads the rental with
     * the scope bypassed — it must not price a foreign rental either, which
     * would leak another landlord's monthly_rent back into the UI.
     */
    public function test_simple_billing_rent_recalculation_rejects_a_foreign_rental(): void
    {
        [$landlordA, $propertyA] = $this->createLandlordWithRoom('A');
        [, , $rentalB] = $this->createLandlordWithRoom('B', monthlyRent: 999);

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(SimpleBillingInvoice::class)
            ->set('propertyId', $propertyA->id)
            ->call('startBilling')
            ->set('rooms.0.rental_id', $rentalB->id)
            ->set('rooms.0.period_end', '2026-07-04')
            ->assertForbidden();
    }

    /** The same-landlord Simple Mode run must keep working. */
    public function test_simple_billing_still_bills_the_acting_landlords_own_rental(): void
    {
        [$landlordA, $propertyA, $rentalA] = $this->createLandlordWithRoom('A');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(SimpleBillingInvoice::class)
            ->set('propertyId', $propertyA->id)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices');

        $this->assertDatabaseHas('invoices', [
            'rental_id' => $rentalA->id,
            'landlord_id' => $landlordA->id,
        ]);
    }

    // --- InvoiceResource CreateInvoice ---------------------------------------

    /**
     * `rental_id` is an unvalidated Hidden field on the invoice form, so the
     * submitted value is whatever the browser sends. Creating an invoice
     * against landlord B's rental would write an Invoice (and UtilityUsage
     * rows) carrying B's landlord_id.
     */
    public function test_create_invoice_page_rejects_a_rental_belonging_to_another_landlord(): void
    {
        [$landlordA, $propertyA, $rentalA] = $this->createLandlordWithRoom('A');
        [, , $rentalB] = $this->createLandlordWithRoom('B');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'unit_id' => $rentalA->unit_id,
                'monthly_rent' => '500',
                'include_rent' => true,
                'payment_status' => InvoiceStatus::Pending->value,
                'issue_date' => '2026-07-05',
            ])
            ->set('data.rental_id', $rentalB->id)
            ->set('data.period_start', '2026-07-01')
            ->set('data.period_end', '2026-07-05')
            ->set('data.due_date', '2026-07-12')
            ->call('create')
            ->assertForbidden();

        $this->assertDatabaseMissing('invoices', ['rental_id' => $rentalB->id]);
    }

    /** The counterpart: landlord A can still create an invoice for their own room. */
    public function test_create_invoice_page_still_creates_for_the_acting_landlords_own_rental(): void
    {
        [$landlordA, $propertyA, $rentalA] = $this->createLandlordWithRoom('A');

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'unit_id' => $rentalA->unit_id,
                'monthly_rent' => '500',
                'include_rent' => true,
                'payment_status' => InvoiceStatus::Pending->value,
                'issue_date' => '2026-07-05',
            ])
            ->set('data.period_start', '2026-07-01')
            ->set('data.period_end', '2026-07-05')
            ->set('data.due_date', '2026-07-12')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('invoices', [
            'rental_id' => $rentalA->id,
            'landlord_id' => $landlordA->id,
        ]);
    }

    // --- InvoiceResource EditInvoice -----------------------------------------

    /**
     * `readings.*.utility_usage_id` is a Hidden repeater field. Editing one's
     * own invoice while pointing a reading row at landlord B's UtilityUsage id
     * used to write straight through to B's row (the "does this line belong to
     * this invoice?" lookup happened only afterwards).
     */
    public function test_edit_invoice_rejects_a_utility_usage_belonging_to_another_landlord(): void
    {
        [$landlordA, $propertyA, $rentalA, $utilityA] = $this->createLandlordWithRoom('A');
        [, , $rentalB, $utilityB] = $this->createLandlordWithRoom('B');

        $usageB = UtilityUsage::create([
            'property_utility_id' => $utilityB->id,
            'unit_id' => $rentalB->unit_id,
            'rental_id' => $rentalB->id,
            'landlord_id' => $rentalB->landlord_id,
            'recorded_by_id' => $rentalB->landlord_id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => '2026-07-05',
            'old_reading' => 100,
            'new_reading' => 110,
            'amount_used' => 10,
        ]);

        $invoiceA = $this->createInvoiceWithReading($landlordA, $rentalA, $utilityA);

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        Livewire::test(EditInvoice::class, ['record' => $invoiceA->getRouteKey()])
            ->set('data.readings.0.utility_usage_id', $usageB->id)
            ->set('data.readings.0.old_reading', '0')
            ->set('data.readings.0.new_reading', '99999')
            ->call('save')
            ->assertForbidden();

        $usageB->refresh();
        $this->assertEquals(110.0, (float) $usageB->new_reading);
        $this->assertEquals(10.0, (float) $usageB->amount_used);
    }

    /** The counterpart: editing one's own invoice readings still persists. */
    public function test_edit_invoice_still_updates_the_acting_landlords_own_reading(): void
    {
        [$landlordA, $propertyA, $rentalA, $utilityA] = $this->createLandlordWithRoom('A');

        $invoiceA = $this->createInvoiceWithReading($landlordA, $rentalA, $utilityA);
        $usageA = UtilityUsage::withoutGlobalScopes()->where('rental_id', $rentalA->id)->firstOrFail();

        $this->actingAs($landlordA);
        ActiveProperty::set($propertyA->id);

        $component = Livewire::test(EditInvoice::class, ['record' => $invoiceA->getRouteKey()]);

        // Filament keys repeater rows by a generated uuid, not by index.
        $rowKey = array_key_first($component->get('data.readings'));

        $component
            ->set("data.readings.{$rowKey}.new_reading", '160')
            ->call('save')
            ->assertHasNoFormErrors();

        $usageA->refresh();
        $this->assertEquals(160.0, (float) $usageA->new_reading);
    }

    // --- Fixtures ------------------------------------------------------------

    /**
     * @return array{0: User, 1: Property, 2: Rental, 3: PropertyUtility}
     */
    private function createLandlordWithRoom(string $suffix, float $monthlyRent = 500): array
    {
        $landlord = User::create([
            'name' => 'Landlord '.$suffix,
            'email' => 'landlord-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property '.$suffix,
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV'.$suffix,
            'monthly_billing_enabled' => true,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
        ]);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $tenant = User::create([
            'name' => 'Tenant '.$suffix,
            'email' => 'tenant-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '10'.$suffix,
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Occupant '.$suffix,
            'monthly_rent' => $monthlyRent,
            'status' => RentalStatus::Active,
            'start_date' => '2026-07-01',
            'next_invoice_date' => null,
        ]);

        return [$landlord, $property, $rental, $utility];
    }

    /** An invoice with one utility line backed by a real UtilityUsage row. */
    private function createInvoiceWithReading(User $landlord, Rental $rental, PropertyUtility $utility): Invoice
    {
        $usage = UtilityUsage::create([
            'property_utility_id' => $utility->id,
            'unit_id' => $rental->unit_id,
            'rental_id' => $rental->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => '2026-07-05',
            'old_reading' => 130,
            'new_reading' => 150,
            'amount_used' => 20,
        ]);

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => 'INV-'.uniqid(),
            'amount_due' => 505,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-05',
            'issue_date' => '2026-07-05',
            'due_date' => '2026-07-12',
            'payment_status' => InvoiceStatus::Pending,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceLineType::Rent,
            'description' => 'Monthly rent',
            'quantity' => 1,
            'unit_price' => 500,
            'amount' => 500,
            'currency' => 'USD',
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceLineType::Utility,
            'utility_usage_id' => $usage->id,
            'description' => 'Electricity',
            'quantity' => 20,
            'unit_price' => 0.25,
            'amount' => 5,
            'currency' => 'USD',
        ]);

        return $invoice->fresh(['lines.utilityUsage.propertyUtility']);
    }
}
