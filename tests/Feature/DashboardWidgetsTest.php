<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Filament\Widgets\BillingCycleWidget;
use App\Filament\Widgets\LeaseExpiryWidget;
use App\Filament\Widgets\PortfolioStatsWidget;
use App\Filament\Widgets\ReceivablesAgingWidget;
use App\Filament\Widgets\RecentPaymentsWidget;
use App\Filament\Widgets\RevenueChartWidget;
use App\Filament\Widgets\RoomsMissingReadingsWidget;
use App\Filament\Widgets\TopDebtorsWidget;
use App\Filament\Widgets\UtilityAnomalyWidget;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    // ── Property scoping ───────────────────────────────────────────────────────

    public function test_portfolio_stats_follow_the_active_property(): void
    {
        $landlord = $this->makeLandlord();

        // Full house.
        $letProperty = $this->makeProperty($landlord, 'Fully let');
        $this->makeUnit($letProperty, '101', status: UnitStatus::Occupied);
        $this->makeUnit($letProperty, '102', status: UnitStatus::Occupied);

        // Half empty.
        $emptyProperty = $this->makeProperty($landlord, 'Half empty');
        $this->makeUnit($emptyProperty, '201', status: UnitStatus::Occupied);
        $this->makeUnit($emptyProperty, '202', status: UnitStatus::Available);

        $this->actingAs($landlord);

        ActiveProperty::set($letProperty->id);
        Livewire::actingAs($landlord)->test(PortfolioStatsWidget::class)
            ->assertSee('100%')
            ->assertDontSee('50%');

        ActiveProperty::set($emptyProperty->id);
        Livewire::actingAs($landlord)->test(PortfolioStatsWidget::class)
            ->assertSee('50%')
            ->assertDontSee('100%');
    }

    public function test_recent_payments_never_leak_across_landlords(): void
    {
        [$mine, $property] = [$this->makeLandlord(), null];
        $property = $this->makeProperty($mine, 'Mine');
        $unit = $this->makeUnit($property, '101');
        $rental = $this->makeRental($mine, $unit);
        $invoice = $this->makeInvoice($mine, $rental, amountDue: 100);
        $invoice->recordPayment(['amount' => 40, 'currency' => 'USD', 'paid_at' => now(), 'recorded_by_id' => $mine->id]);

        $theirs = $this->makeLandlord();
        $theirProperty = $this->makeProperty($theirs, 'Theirs');
        $theirUnit = $this->makeUnit($theirProperty, '901');
        $theirRental = $this->makeRental($theirs, $theirUnit);
        $theirInvoice = $this->makeInvoice($theirs, $theirRental, amountDue: 700, number: 'INV-THEIRS');
        $theirInvoice->recordPayment(['amount' => 700, 'currency' => 'USD', 'paid_at' => now(), 'recorded_by_id' => $theirs->id]);

        $this->actingAs($mine);

        Livewire::actingAs($mine)->test(RecentPaymentsWidget::class)
            ->assertSee('101')
            ->assertDontSee('901')
            ->assertDontSee('INV-THEIRS');
    }

    // ── Billing cycle ──────────────────────────────────────────────────────────

    public function test_billing_cycle_counts_each_room_and_utility_pair(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Metered', monthlyBilling: true);
        $power = $this->makeUtility($property, 'Electricity');
        $water = $this->makeUtility($property, 'Water');

        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);

        // Only one of the room's two metered utilities has been read.
        $this->makeReading($landlord, $unit, $power, $rental, amountUsed: 50);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(BillingCycleWidget::class)
            ->assertSee('1 / 2');
    }

    public function test_billing_cycle_ignores_readings_from_other_months(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Metered', monthlyBilling: true);
        $power = $this->makeUtility($property, 'Electricity');
        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);

        $this->makeReading($landlord, $unit, $power, $rental, amountUsed: 50, readOn: now()->subMonthNoOverflow());

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(BillingCycleWidget::class)
            ->assertSee('0 / 1');
    }

    // ── Rooms missing readings ─────────────────────────────────────────────────

    public function test_partially_read_room_is_flagged_as_missing(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Metered', monthlyBilling: true);
        $power = $this->makeUtility($property, 'Electricity');
        $this->makeUtility($property, 'Water');

        $partial = $this->makeUnit($property, 'PARTIAL', status: UnitStatus::Occupied);
        $partialRental = $this->makeRental($landlord, $partial);
        $this->makeReading($landlord, $partial, $power, $partialRental, amountUsed: 10);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(RoomsMissingReadingsWidget::class)
            ->assertCanSeeTableRecords([$partial]);
    }

    public function test_fully_read_room_is_not_flagged(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Metered', monthlyBilling: true);
        $power = $this->makeUtility($property, 'Electricity');
        $water = $this->makeUtility($property, 'Water');

        $done = $this->makeUnit($property, 'DONE', status: UnitStatus::Occupied);
        $doneRental = $this->makeRental($landlord, $done);
        $this->makeReading($landlord, $done, $power, $doneRental, amountUsed: 10);
        $this->makeReading($landlord, $done, $water, $doneRental, amountUsed: 3);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(RoomsMissingReadingsWidget::class)
            ->assertCanNotSeeTableRecords([$done]);
    }

    public function test_vacant_room_is_never_chased_for_a_reading(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Metered', monthlyBilling: true);
        $this->makeUtility($property, 'Electricity');
        $vacant = $this->makeUnit($property, 'VACANT', status: UnitStatus::Available);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(RoomsMissingReadingsWidget::class)
            ->assertCanNotSeeTableRecords([$vacant]);
    }

    // ── Who to chase ───────────────────────────────────────────────────────────

    public function test_top_debtors_rolls_every_open_invoice_up_to_one_tenancy(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Debtors');
        $unit = $this->makeUnit($property, '101');
        $rental = $this->makeRental($landlord, $unit);

        $this->makeInvoice($landlord, $rental, amountDue: 100, number: 'A', dueDate: now()->subDays(70));
        $this->makeInvoice($landlord, $rental, amountDue: 100, number: 'B', dueDate: now()->subDays(40));
        $this->makeInvoice($landlord, $rental, amountDue: 100, number: 'C', dueDate: now()->subDays(10));

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(TopDebtorsWidget::class)
            ->assertCanSeeTableRecords([$rental])
            ->assertSee('$300.00')   // three invoices, one row
            ->assertSee('3');
    }

    public function test_top_debtors_ranks_the_largest_balance_first(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Debtors');

        $small = $this->makeRental($landlord, $this->makeUnit($property, 'SMALL'));
        $big = $this->makeRental($landlord, $this->makeUnit($property, 'BIG'));

        $this->makeInvoice($landlord, $small, amountDue: 50, number: 'S1');
        $this->makeInvoice($landlord, $big, amountDue: 900, number: 'B1');

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(TopDebtorsWidget::class)
            ->assertCanSeeTableRecords([$big, $small], inOrder: true);
    }

    public function test_top_debtors_leaves_out_a_settled_tenancy(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Debtors');
        $rental = $this->makeRental($landlord, $this->makeUnit($property, '101'));

        $invoice = $this->makeInvoice($landlord, $rental, amountDue: 200);
        $invoice->recordPayment(['amount' => 200, 'currency' => 'USD', 'paid_at' => now(), 'recorded_by_id' => $landlord->id]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(TopDebtorsWidget::class)
            ->assertCanNotSeeTableRecords([$rental]);
    }

    // ── Receivables aging ──────────────────────────────────────────────────────

    public function test_aging_separates_recent_arrears_from_long_standing_debt(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Aging');
        $rental = $this->makeRental($landlord, $this->makeUnit($property, '101'));

        $this->makeInvoice($landlord, $rental, amountDue: 10, number: 'FUTURE', dueDate: now()->addDays(5));
        $this->makeInvoice($landlord, $rental, amountDue: 20, number: 'RECENT', dueDate: now()->subDays(10));
        $this->makeInvoice($landlord, $rental, amountDue: 40, number: 'MID', dueDate: now()->subDays(45));
        $this->makeInvoice($landlord, $rental, amountDue: 80, number: 'OLD', dueDate: now()->subDays(200));

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $widget = new ReceivablesAgingWidget;
        $data = $this->callProtected($widget, 'getData');

        $this->assertSame([10.0, 20.0, 40.0, 80.0], $data['datasets'][0]['data']);
    }

    // ── Revenue chart: the mixed-currency regression ───────────────────────────

    public function test_revenue_chart_converts_instead_of_adding_native_amounts(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Mixed currency');
        $rental = $this->makeRental($landlord, $this->makeUnit($property, '101'));

        $invoice = $this->makeInvoice($landlord, $rental, amountDue: 70, number: 'MIX');
        $invoice->forceFill(['usd_khr_rate' => 4000])->save();

        // $50 rent alongside ៛80,000 of water — routine here, and the exact shape
        // that used to be summed natively into "80,050 $".
        $this->makeLine($invoice, amount: 50, currency: 'USD', usd: 50, khr: 200000);
        $this->makeLine($invoice, amount: 80000, currency: 'KHR', usd: 20, khr: 80000, type: InvoiceLineType::Utility);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $widget = new RevenueChartWidget;
        $data = $this->callProtected($widget, 'getData');
        $thisMonth = $data['datasets'][0]['data'][now()->month - 1];

        $this->assertSame(70.0, $thisMonth, 'Revenue must be $50 + ៛80,000-as-$20, never 50 + 80000.');
        $this->assertNotSame(80050.0, $thisMonth);
    }

    // ── Utility anomalies ──────────────────────────────────────────────────────

    public function test_a_consumption_spike_is_surfaced(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Leaky');
        $water = $this->makeUtility($property, 'Water');
        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);

        $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 10, readOn: now()->subMonthsNoOverflow(3));
        $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 10, readOn: now()->subMonthsNoOverflow(2));
        $spike = $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 40, readOn: now()->subMonthNoOverflow());

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(UtilityAnomalyWidget::class)
            ->assertCanSeeTableRecords([$spike])
            ->assertSee('×4.0');
    }

    public function test_steady_consumption_is_not_an_anomaly(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Steady');
        $water = $this->makeUtility($property, 'Water');
        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);

        $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 10, readOn: now()->subMonthsNoOverflow(3));
        $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 11, readOn: now()->subMonthsNoOverflow(2));
        $latest = $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 12, readOn: now()->subMonthNoOverflow());

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(UtilityAnomalyWidget::class)
            ->assertCanNotSeeTableRecords([$latest]);
    }

    public function test_a_room_without_history_is_never_called_anomalous(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'New');
        $water = $this->makeUtility($property, 'Water');
        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);

        $first = $this->makeReading($landlord, $unit, $water, $rental, amountUsed: 999);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(UtilityAnomalyWidget::class)
            ->assertCanNotSeeTableRecords([$first]);
    }

    // ── Lease expiry ───────────────────────────────────────────────────────────

    public function test_leases_ending_soon_are_listed_and_distant_ones_are_not(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Expiring');

        $soon = $this->makeRental($landlord, $this->makeUnit($property, 'SOON'), endDate: now()->addDays(14));
        $later = $this->makeRental($landlord, $this->makeUnit($property, 'LATER'), endDate: now()->addDays(180));

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(LeaseExpiryWidget::class)
            ->assertCanSeeTableRecords([$soon])
            ->assertCanNotSeeTableRecords([$later]);
    }

    // ── The page itself ────────────────────────────────────────────────────────

    public function test_the_dashboard_renders_with_every_widget_registered(): void
    {
        $landlord = $this->makeLandlord();
        $property = $this->makeProperty($landlord, 'Rendering', monthlyBilling: true);
        $this->makeUtility($property, 'Electricity');
        $unit = $this->makeUnit($property, '101', status: UnitStatus::Occupied);
        $rental = $this->makeRental($landlord, $unit);
        $this->makeInvoice($landlord, $rental, amountDue: 120);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $this->get('/app')->assertSuccessful();
    }

    // ── Scaffolding ────────────────────────────────────────────────────────────

    private function callProtected(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Landlord '.uniqid(),
            'email' => 'landlord-dashboard-'.uniqid().'@example.com',
        ]);
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'starter'], [
            'name' => 'Starter',
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'trial_days' => 0,
            'grace_days' => 7,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonth()->endOfMonth(),
            'auto_renew' => true,
        ]);

        return $landlord;
    }

    private function makeProperty(User $landlord, string $name, bool $monthlyBilling = false): Property
    {
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => $name]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'usd_khr_exchange_rate' => 4000,
            'monthly_billing_enabled' => $monthlyBilling,
        ]);

        return $property->refresh();
    }

    private function makeUnit(Property $property, string $room, UnitStatus $status = UnitStatus::Occupied): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'rent_amount' => 240,
            'rent_currency' => 'USD',
            'status' => $status,
        ]);
    }

    private function makeRental(User $landlord, Unit $unit, ?\Carbon\Carbon $endDate = null): Rental
    {
        $tenant = User::factory()->create(['email' => 'tenant-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');

        return Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Occupant '.$unit->room_number,
            'occupant_phone' => '012345678',
            'monthly_rent' => 240,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 240,
            'security_deposit_currency' => 'USD',
            'status' => RentalStatus::Active,
            'start_date' => now()->subMonthsNoOverflow(6)->toDateString(),
            'end_date' => $endDate?->toDateString(),
        ]);
    }

    private function makeInvoice(
        User $landlord,
        Rental $rental,
        float $amountDue,
        ?string $number = null,
        ?\Carbon\Carbon $dueDate = null,
    ): Invoice {
        return Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $rental->property_id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => $number ?? 'INV-'.uniqid(),
            'amount_due' => $amountDue,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => $dueDate ?? now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);
    }

    private function makeLine(
        Invoice $invoice,
        float $amount,
        string $currency,
        float $usd,
        float $khr,
        InvoiceLineType $type = InvoiceLineType::Rent,
    ): InvoiceLine {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => $type,
            'description' => 'Line',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'currency' => $currency,
            'unit_price_currency' => $currency,
            'amount_usd' => $usd,
            'amount_khr' => $khr,
            'unit_price_usd' => $usd,
            'unit_price_khr' => $khr,
            'exchange_rate' => 4000,
        ]);
    }

    private function makeUtility(Property $property, string $name): PropertyUtility
    {
        return PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'name' => $name,
            'unit_of_measure' => 'unit',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function makeReading(
        User $landlord,
        Unit $unit,
        PropertyUtility $utility,
        Rental $rental,
        float $amountUsed,
        ?\Carbon\Carbon $readOn = null,
    ): UtilityUsage {
        return UtilityUsage::create([
            'property_utility_id' => $utility->id,
            'unit_id' => $unit->id,
            'rental_id' => $rental->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_date' => ($readOn ?? now())->toDateString(),
            'old_reading' => 0,
            'new_reading' => $amountUsed,
            'amount_used' => $amountUsed,
            'is_waived' => false,
        ]);
    }
}
