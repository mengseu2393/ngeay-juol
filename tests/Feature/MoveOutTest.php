<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\DepositDeductionCategory;
use App\Enums\DepositSettlementStatus;
use App\Enums\FirstMonthBillingMode;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\RentalResource\Pages\ListRentals;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\MoveOutService;
use App\Services\TenancyService;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Move-out: the exit half of a tenancy.
 *
 * Before MoveOutService the only "move out" was flipping the status dropdown to
 * Vacated — no closing reading, no closing invoice, and no exit path at all for
 * the security deposit collected on every tenancy. These tests pin the four
 * things that were missing and the one regression that is easy to reintroduce
 * (the room staying Occupied after its tenant leaves).
 *
 * Fixtures are deliberately built without any move-in state: nothing in the app
 * currently populates `move_in_status` / `moved_in_at`, so a move-out that only
 * worked for "properly moved-in" tenancies would work for no real tenancy at
 * all.
 */
class MoveOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        Carbon::setTestNow('2026-09-10 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_move_out_bills_prorated_final_period_and_settles_the_deposit(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'utility' => $utility, 'unit' => $unit] = $this->fixture();
        $this->actingAs($landlord);

        $result = app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'move_out_reason' => 'Relocating for work',
            'final_readings' => [$utility->id => 150],
            'create_final_invoice' => true,
            'prorate_final_rent' => true,
            'deductions' => [
                ['category' => DepositDeductionCategory::Damage->value, 'reason' => 'Broken window', 'amount' => 50, 'currency' => 'USD'],
            ],
        ], $landlord->id);

        // --- closing invoice: prorated rent + the utility consumed on the way out
        $invoice = $result['invoice'];
        $this->assertNotNull($invoice);
        $this->assertSame('2026-09-01', Carbon::parse($invoice->period_start)->toDateString());
        $this->assertSame('2026-09-15', Carbon::parse($invoice->period_end)->toDateString());
        // Rent $300 over 15 of September's 30 days = $150; 20 kWh × $0.25 = $5.
        $this->assertEqualsWithDelta(155.00, (float) $invoice->amount_due, 0.001);

        // --- final reading recorded against the closing date, baselined off 130
        $usage = UtilityUsage::where('rental_id', $rental->id)
            ->whereDate('reading_date', '2026-09-15')
            ->firstOrFail();
        $this->assertEqualsWithDelta(130.0, (float) $usage->old_reading, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $usage->new_reading, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $usage->amount_used, 0.001);

        // --- deposit settled: refund = deposit − deductions, in both currencies
        $settlement = $result['settlement'];
        $this->assertEqualsWithDelta(300.00, (float) $settlement->deposit_amount, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $settlement->deductions_total, 0.001);
        $this->assertEqualsWithDelta(250.00, (float) $settlement->refund_amount, 0.001);
        $this->assertSame(DepositSettlementStatus::Settled, $settlement->status);
        $this->assertSame($invoice->id, $settlement->final_invoice_id);
        $this->assertCount(1, $settlement->deductions);

        // --- rental closed and the room handed back
        $rental->refresh();
        $this->assertSame(RentalStatus::Vacated, $rental->status);
        $this->assertSame('2026-09-15', Carbon::parse($rental->move_out_date)->toDateString());
        $this->assertSame($landlord->id, $rental->moved_out_by_id);
        $this->assertNotNull($rental->moved_out_at);
        $this->assertNull($rental->next_invoice_date);
        $this->assertSame(UnitStatus::Available, $unit->refresh()->status);
    }

    /**
     * The KHR twins are the whole reason the settlement stores an exchange_rate:
     * a refund re-read years later must reproduce the riel figure the tenant was
     * actually handed, not today's rate. The rate is snapshotted from the
     * closing invoice (itself taken from the property's saved manual rate), so
     * $300 − $50 = $250 must come back as 1,230,000៛ − 205,000៛ = 1,025,000៛,
     * NOT as $250 re-converted at whatever rate happens to be current.
     */
    public function test_deposit_settlement_snapshots_khr_twins_at_the_saved_rate(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'utility' => $utility] = $this->fixture();
        $this->actingAs($landlord);

        $result = app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'final_readings' => [$utility->id => 150],
            'deductions' => [
                ['category' => DepositDeductionCategory::LostKeys->value, 'reason' => 'Lost key', 'amount' => 50, 'currency' => 'USD'],
            ],
        ], $landlord->id);

        $settlement = $result['settlement'];
        $this->assertEqualsWithDelta(4100.0, (float) $settlement->exchange_rate, 0.001);
        $this->assertEqualsWithDelta(1_230_000, (float) $settlement->deposit_amount_khr, 0.5);
        $this->assertEqualsWithDelta(205_000, (float) $settlement->deductions_total_khr, 0.5);
        $this->assertEqualsWithDelta(1_025_000, (float) $settlement->refund_amount_khr, 0.5);
        $this->assertEqualsWithDelta(250.00, (float) $settlement->refund_amount_usd, 0.001);

        $deduction = $settlement->deductions->first();
        $this->assertEqualsWithDelta(4100.0, (float) $deduction->exchange_rate, 0.001);
        $this->assertEqualsWithDelta(205_000, (float) $deduction->amount_khr, 0.5);
        $this->assertSame($settlement->landlord_id, $deduction->landlord_id);
    }

    public function test_move_out_with_no_deductions_refunds_the_whole_deposit(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $settlement = app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
        ], $landlord->id)['settlement'];

        $this->assertEqualsWithDelta(0.0, (float) $settlement->deductions_total, 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $settlement->refund_amount, 0.001);
        $this->assertEqualsWithDelta(1_230_000, (float) $settlement->refund_amount_khr, 0.5);
        $this->assertSame(0, Invoice::count());
    }

    public function test_recording_the_refund_payment_marks_the_settlement_refunded(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $settlement = app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
            'refund_reference' => 'ABA-88213',
            'refund_paid_at' => '2026-09-16',
        ], $landlord->id)['settlement'];

        $this->assertSame(DepositSettlementStatus::Refunded, $settlement->status);
        $this->assertSame('ABA-88213', $settlement->refund_reference);
    }

    /**
     * The regression that matters most operationally: a landlord who moves a
     * tenant out and cannot then re-let the room has a broken product. The room
     * must go back to Available AND `TenancyService::hasActiveTenancy()` (which
     * backs the DB's one-active-tenancy-per-unit index) must stop counting the
     * old tenancy, or creating the next one fails.
     */
    public function test_move_out_frees_the_room_for_the_next_tenancy(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'unit' => $unit] = $this->fixture();
        $this->actingAs($landlord);

        $this->assertSame(UnitStatus::Occupied, $unit->refresh()->status);

        app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
        ], $landlord->id);

        $this->assertSame(UnitStatus::Available, $unit->refresh()->status);
        $this->assertFalse(TenancyService::hasActiveTenancy($unit->id));

        $nextTenant = User::create([
            'name' => 'Next Occupant',
            'email' => 'next-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $nextTenant->assignRole('tenant');

        $next = Rental::create([
            'property_id' => $unit->property_id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $nextTenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Next Occupant',
            'monthly_rent' => 320,
            'security_deposit' => 320,
            'status' => RentalStatus::Active,
            'start_date' => '2026-09-16',
        ]);

        $this->assertSame(RentalStatus::Active, $next->refresh()->status);
        $this->assertSame(UnitStatus::Occupied, $unit->refresh()->status);
    }

    public function test_moving_out_an_already_vacated_tenancy_is_rejected(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
        ], $landlord->id);

        $this->expectException(\DomainException::class);
        app(MoveOutService::class)->execute($rental->refresh(), [
            'move_out_date' => '2026-09-20',
            'create_final_invoice' => false,
        ], $landlord->id);
    }

    public function test_move_out_date_before_the_start_date_is_rejected(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $this->expectException(ValidationException::class);
        app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-05-01', // tenancy started 2026-06-01
            'create_final_invoice' => false,
        ], $landlord->id);
    }

    public function test_deductions_exceeding_the_deposit_are_rejected(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $this->expectException(ValidationException::class);
        app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
            'deductions' => [
                ['category' => DepositDeductionCategory::Damage->value, 'reason' => 'Repainting', 'amount' => 400, 'currency' => 'USD'],
            ],
        ], $landlord->id);
    }

    public function test_a_deduction_without_a_reason_is_rejected(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $this->expectException(ValidationException::class);
        app(MoveOutService::class)->execute($rental, [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => false,
            'deductions' => [
                ['category' => DepositDeductionCategory::Other->value, 'reason' => '  ', 'amount' => 20, 'currency' => 'USD'],
            ],
        ], $landlord->id);
    }

    /**
     * The whole move-out is one transaction, and the deposit guard fires AFTER
     * the closing invoice is built (it prices deductions at the rate that
     * invoice snapshotted). That ordering is only safe because a failure rolls
     * the invoice back — otherwise a rejected move-out would leave the tenant
     * holding a real, unretractable bill and the room still occupied.
     */
    public function test_a_failure_part_way_through_leaves_no_partial_invoice(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'utility' => $utility, 'unit' => $unit] = $this->fixture();
        $this->actingAs($landlord);

        try {
            app(MoveOutService::class)->execute($rental, [
                'move_out_date' => '2026-09-15',
                'final_readings' => [$utility->id => 150],
                'create_final_invoice' => true,
                'deductions' => [
                    ['category' => DepositDeductionCategory::Damage->value, 'reason' => 'Repainting', 'amount' => 400, 'currency' => 'USD'],
                ],
            ], $landlord->id);
            $this->fail('Expected the over-deduction guard to reject the move-out.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DepositSettlement::count());
        $this->assertSame(0, UtilityUsage::whereDate('reading_date', '2026-09-15')->count());

        $rental->refresh();
        $this->assertSame(RentalStatus::Active, $rental->status);
        $this->assertNull($rental->moved_out_at);
        $this->assertSame(UnitStatus::Occupied, $unit->refresh()->status);
    }

    /**
     * A tenancy already billed through its move-out day has no rent left to
     * charge; the closing run must not invent a second invoice for a period it
     * has already been billed for.
     */
    public function test_a_tenancy_billed_past_the_move_out_date_gets_no_rent_invoice(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $rental->forceFill(['next_invoice_date' => '2026-10-01'])->saveQuietly();
        $this->actingAs($landlord);

        $result = app(MoveOutService::class)->execute($rental->refresh(), [
            'move_out_date' => '2026-09-15',
            'create_final_invoice' => true,
        ], $landlord->id);

        $this->assertNull($result['invoice']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(RentalStatus::Vacated, $rental->refresh()->status);
    }

    public function test_move_out_action_runs_from_the_tenancy_table(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'utility' => $utility, 'property' => $property, 'unit' => $unit] = $this->fixture();
        ActiveProperty::set($property->id);
        $this->actingAs($landlord);

        Livewire::test(ListRentals::class)
            ->assertCanSeeTableRecords([$rental])
            ->callTableAction('move_out', $rental, [
                'move_out_date' => '2026-09-15',
                'create_final_invoice' => true,
                'prorate_final_rent' => true,
                'final_readings' => [$utility->id => 150],
                'deductions' => [
                    ['category' => DepositDeductionCategory::Cleaning->value, 'reason' => 'Deep clean', 'amount' => 30, 'currency' => 'USD'],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(RentalStatus::Vacated, $rental->refresh()->status);
        $this->assertSame(UnitStatus::Available, $unit->refresh()->status);
        $this->assertEqualsWithDelta(270.00, (float) DepositSettlement::firstOrFail()->refund_amount, 0.001);
    }

    /**
     * @return array{landlord: User, property: Property, unit: Unit, rental: Rental, utility: PropertyUtility}
     */
    private function fixture(): array
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Riverside',
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'create_invoice_on_move_in' => false,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
            // Manual rate keeps InvoiceBuilderService off the network (and gives
            // the settlement a deterministic rate to snapshot).
            'exchange_rate_source' => 'manual',
            'usd_khr_exchange_rate' => 4100,
        ]);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'currency' => 'USD',
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        // rentals.tenant_id is NOT NULL — every tenancy carries a portal user.
        $tenant = User::create([
            'name' => 'Sok Dara',
            'email' => 'tenant-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 300,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 300,
            'security_deposit_currency' => 'USD',
            'status' => RentalStatus::Active,
            'start_date' => '2026-06-01',
            'next_invoice_date' => '2026-09-01',
        ]);

        UtilityUsage::create([
            'property_utility_id' => $utility->id,
            'unit_id' => $unit->id,
            'rental_id' => $rental->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => '2026-08-31',
            'old_reading' => 110,
            'new_reading' => 130,
            'amount_used' => 20,
        ]);

        return compact('landlord', 'property', 'unit', 'rental', 'utility');
    }
}
