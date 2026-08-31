<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\InvoiceStatus;
use App\Enums\MeterScope;
use App\Enums\MeterStatus;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('landlord'));
        Carbon::setTestNow('2026-07-05 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_page_loads_due_rooms_with_previous_readings(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 2, previousReading: 130);

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class)
            ->assertSet('propertyId', $property->id);

        $rooms = $test->instance()->rooms;
        $this->assertCount(2, $rooms);

        foreach ($rooms as $room) {
            $this->assertTrue($room['include'], 'Due rooms should start ticked');
            $this->assertSame('130', $room['utilities'][0]['old_reading']);
            $this->assertNull($room['utilities'][0]['new_reading']);
        }
    }

    public function test_bills_multiple_rooms_with_only_new_readings(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 2, previousReading: 130);
        $rentals = Rental::where('property_id', $property->id)->orderBy('id')->get();
        foreach ($rentals as $rental) {
            $this->createPriorInvoice($rental, '2026-06-01', '2026-06-30');
        }

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->set('rooms.1.utilities.0.new_reading', '145')
            ->call('createInvoices');

        $this->assertSame(2, $test->instance()->lastRun['created']);
        $this->assertSame(0, $test->instance()->lastRun['failed']);

        $invoices = Invoice::whereDate('period_start', '2026-07-01')
            ->whereDate('period_end', '2026-07-05')
            ->orderBy('rental_id')
            ->get();
        $this->assertCount(2, $invoices);

        // Rent 500 + usage × $0.25: (150-130)=20 → $5, (145-130)=15 → $3.75
        $this->assertEquals(505.00, (float) $invoices[0]->amount_due);
        $this->assertEquals(503.75, (float) $invoices[1]->amount_due);

        $usages = UtilityUsage::whereDate('reading_date', '2026-07-05')->orderBy('rental_id')->get();
        $this->assertCount(2, $usages);
        $this->assertEquals(20.0, (float) $usages[0]->amount_used);
        $this->assertEquals(15.0, (float) $usages[1]->amount_used);

        // Schedule advanced (period end 07-05 → next day's month start).
        foreach ($rentals as $rental) {
            $this->assertSame('2026-07-01', Carbon::parse($rental->refresh()->next_invoice_date)->toDateString());
        }
    }

    public function test_room_missing_a_reading_is_skipped_not_failed(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 2, previousReading: 130);
        foreach (Rental::where('property_id', $property->id)->get() as $rental) {
            $this->createPriorInvoice($rental, '2026-06-01', '2026-06-30');
        }

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            // room 1 stays ticked but has no reading
            ->call('createInvoices');

        $this->assertSame(1, $test->instance()->lastRun['created']);
        $this->assertSame(1, $test->instance()->lastRun['skipped']);
        $this->assertSame(0, $test->instance()->lastRun['failed']);
        $this->assertSame(1, Invoice::whereDate('period_end', '2026-07-05')->count());
    }

    public function test_unticked_room_is_not_billed(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 2, previousReading: 130);
        foreach (Rental::where('property_id', $property->id)->get() as $rental) {
            $this->createPriorInvoice($rental, '2026-06-01', '2026-06-30');
        }

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->set('rooms.1.utilities.0.new_reading', '145')
            ->set('rooms.1.include', false)
            ->call('createInvoices');

        $this->assertSame(1, $test->instance()->lastRun['created']);
        $this->assertSame(1, Invoice::whereDate('period_end', '2026-07-05')->count());

        $excludedRentalId = $test->instance()->rooms[1]['rental_id'] ?? null;
        // After the run rooms reload sorted by room number, so re-check by rental.
        $this->assertSame(0, Invoice::whereDate('period_end', '2026-07-05')
            ->where('rental_id', $excludedRentalId)->count());
    }

    public function test_billed_room_locks_and_cannot_be_billed_twice(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 1, previousReading: 130);
        $rental = Rental::where('property_id', $property->id)->firstOrFail();
        $this->createPriorInvoice($rental, '2026-06-01', '2026-06-30');

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class)
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices');

        $this->assertSame(2, Invoice::count());

        // After the run the room reloads with nothing left to bill.
        $instance = $test->instance();
        $this->assertFalse($instance->rooms[0]['include']);
        $this->assertFalse($instance->roomIsReady(0));

        // A second run creates nothing.
        $test->call('createInvoices');
        $this->assertSame(2, Invoice::count());
    }

    public function test_first_invoice_rent_is_prorated(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 1);
        $rental = Rental::where('property_id', $property->id)->firstOrFail();
        $rental->update(['start_date' => '2026-07-01']);

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class);

        // 5 of July's 31 days: 500 × 5/31 = 80.65
        $this->assertEquals(80.65, $test->instance()->rooms[0]['rent']);
        $this->assertTrue($test->instance()->rooms[0]['is_first_invoice']);

        $test->set('rooms.0.utilities.0.new_reading', '100')
            ->call('createInvoices');

        $invoice = Invoice::whereDate('period_end', '2026-07-05')->firstOrFail();
        // No previous reading → previous index 0 → usage 100 × $0.25 = $25.
        $this->assertEquals(105.65, (float) $invoice->amount_due);
    }

    public function test_meter_previous_reading_and_multiplier_apply(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 1);
        $rental = Rental::where('property_id', $property->id)->firstOrFail();
        $this->createPriorInvoice($rental, '2026-06-01', '2026-06-30');
        $utility = PropertyUtility::where('property_id', $property->id)->firstOrFail();

        UtilityMeter::create([
            'property_utility_id' => $utility->id,
            'landlord_id' => $landlord->id,
            'scope' => MeterScope::Unit,
            'unit_id' => $rental->unit_id,
            'multiplier' => 2,
            'installed_on' => '2026-06-01',
            'installed_reading' => 100,
            'status' => MeterStatus::Active,
        ]);

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class);

        // Previous index comes from the meter's opening reading.
        $this->assertSame('100', $test->instance()->rooms[0]['utilities'][0]['old_reading']);

        $test->set('rooms.0.utilities.0.new_reading', '130')
            ->call('createInvoices');

        $usage = UtilityUsage::whereDate('reading_date', '2026-07-05')->firstOrFail();
        // (130 − 100) × multiplier 2 = 60 units.
        $this->assertEquals(60.0, (float) $usage->amount_used);

        $invoice = Invoice::whereDate('period_end', '2026-07-05')->firstOrFail();
        // Rent 500 + 60 × $0.25 = $515.
        $this->assertEquals(515.00, (float) $invoice->amount_due);
    }

    public function test_changing_issue_date_reloads_periods(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createPropertyWithDueRooms($landlord, 'Prop', 1);
        $this->createPriorInvoice(Rental::where('property_id', $property->id)->firstOrFail(), '2026-06-01', '2026-06-30');

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class);
        $this->assertSame('2026-07-05', $test->instance()->rooms[0]['period_end']);

        $test->set('issueDate', '2026-07-31');
        $this->assertSame('2026-07-31', $test->instance()->rooms[0]['period_end']);
        $this->assertSame('2026-07-01', $test->instance()->rooms[0]['period_start']);
    }

    public function test_select_all_toggle_flips_every_billable_room(): void
    {
        $landlord = $this->createLandlord();
        $this->createPropertyWithDueRooms($landlord, 'Prop', 3, previousReading: 130);

        $this->actingAs($landlord);

        $test = Livewire::test(MonthlyBilling::class);
        $this->assertSame(3, $test->instance()->includedRoomCount());

        $test->call('toggleSelectAll');
        $this->assertSame(0, $test->instance()->includedRoomCount());

        $test->call('toggleSelectAll');
        $this->assertSame(3, $test->instance()->includedRoomCount());
    }

    private function createLandlord(): User
    {
        $user = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('landlord');

        return $user;
    }

    private function createPriorInvoice(Rental $rental, string $start, string $end): Invoice
    {
        return Invoice::create([
            'rental_id' => $rental->id,
            'landlord_id' => $rental->landlord_id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => 'INV-PRIOR-'.uniqid(),
            'amount_due' => 500,
            'period_start' => $start,
            'period_end' => $end,
            'issue_date' => $end,
            'due_date' => Carbon::parse($end)->addDays(7)->toDateString(),
            'payment_status' => InvoiceStatus::Pending,
        ]);
    }

    private function createPropertyWithDueRooms(User $landlord, string $name, int $roomCount, ?float $previousReading = null): Property
    {
        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => $name,
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'monthly_billing_enabled' => true,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => \App\Enums\FirstMonthBillingMode::Prorated,
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

        for ($i = 1; $i <= $roomCount; $i++) {
            $tenant = User::create([
                'name' => 'Tenant '.$i,
                'email' => 'tenant-'.$name.'-'.$i.'-'.uniqid().'@example.com',
                'password' => bcrypt('password'),
            ]);
            $tenant->assignRole('tenant');

            $unit = Unit::create([
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'room_number' => (string) (100 + $i),
                'room_type' => 'Standard',
                'status' => UnitStatus::Available,
            ]);

            $rental = Rental::create([
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'occupant_name' => 'Occupant '.$i,
                'monthly_rent' => 500,
                'status' => RentalStatus::Active,
                'start_date' => '2026-06-01',
                'next_invoice_date' => null,
            ]);

            if ($previousReading !== null) {
                UtilityUsage::create([
                    'property_utility_id' => $utility->id,
                    'unit_id' => $unit->id,
                    'rental_id' => $rental->id,
                    'landlord_id' => $landlord->id,
                    'recorded_by_id' => $landlord->id,
                    'reading_type' => ReadingType::Actual,
                    'reading_date' => '2026-07-01',
                    'old_reading' => $previousReading - 20,
                    'new_reading' => $previousReading,
                    'amount_used' => 20,
                ]);
            }
        }

        return $property;
    }
}
