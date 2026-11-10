<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHY: the first invoice auto-created on move-in took its due date from the tenancy's
 * start month + due_day_of_month and only checked it against the start date. A tenancy
 * entered late (start Nov 1, typed in on Nov 8) got "due Nov 7, issued Nov 8" — overdue
 * the moment it existed. Separately, Invoice::resolvePaymentStatus() turned any unpaid
 * invoice overdue after its due date, even one with nothing owed ($0 rent), so a $0.00
 * invoice showed as overdue. Both were seen on real data in the Vercel test deploy.
 */
class MoveInInvoiceDueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-11-08 10:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_tenancy_entered_after_its_due_day_gets_a_due_date_after_today(): void
    {
        $invoice = $this->moveIn(rent: 40, startDate: '2026-11-01');

        $this->assertSame('2026-11-08', $invoice->issue_date->toDateString());
        $this->assertSame('2026-11-15', $invoice->due_date->toDateString(), 'today + invoice_due_days (7)');
        $this->assertSame(InvoiceStatus::Pending, $invoice->payment_status);
    }

    public function test_a_tenancy_entered_before_its_due_day_keeps_the_due_day_of_month(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');

        $invoice = $this->moveIn(rent: 40, startDate: '2026-11-01');

        $this->assertSame('2026-11-07', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Pending, $invoice->payment_status);
    }

    public function test_a_zero_total_invoice_is_settled_and_never_overdue(): void
    {
        $invoice = $this->moveIn(rent: 0, startDate: '2026-11-01');

        $this->assertSame(0.0, (float) $invoice->amount_due);
        $this->assertSame(InvoiceStatus::Paid, $invoice->payment_status);

        // Well past the due date, a recalculation must still not flip it to Overdue.
        Carbon::setTestNow('2026-12-31 10:00:00');
        $invoice->recalculateFromLedger();

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->payment_status);
    }

    public function test_an_unpaid_invoice_with_an_amount_still_goes_overdue(): void
    {
        $invoice = $this->moveIn(rent: 40, startDate: '2026-11-01');

        Carbon::setTestNow('2026-11-16 10:00:00');
        $invoice->recalculateFromLedger();

        $this->assertSame(InvoiceStatus::Overdue, $invoice->refresh()->payment_status);
    }

    private function moveIn(float $rent, string $startDate): Invoice
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');
        $this->actingAs($landlord);

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Riverside Residences',
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'create_invoice_on_move_in' => true,
            'due_day_of_month' => 7,
            'invoice_due_days' => 7,
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
            'rent_amount' => $rent,
            'rent_currency' => 'USD',
        ]);

        $tenant = User::create([
            'name' => 'Sok Dara',
            'username' => 'tenant-'.uniqid(),
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => $rent,
            'monthly_rent_currency' => 'USD',
            'status' => RentalStatus::Active,
            'start_date' => $startDate,
        ]);

        return Invoice::withoutGlobalScopes()->where('rental_id', $rental->id)->firstOrFail();
    }
}
