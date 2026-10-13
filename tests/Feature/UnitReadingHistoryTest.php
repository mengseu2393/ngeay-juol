<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleUtilityRates;
use App\Livewire\UnitReadingHistory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A reading's old_reading/amount_used are derived from the reading before it, so
 * correcting one must re-chain the next — and a reading on a non-draft invoice
 * must be locked, otherwise the bill would silently stop matching the meter.
 */
class UnitReadingHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-20 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_history_lists_readings_newest_first(): void
    {
        [$landlord, $unit, $utility] = $this->fixture();

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->assertSet('utilityId', $utility->id)
            ->assertSeeInOrder(['20 Mar 2026', '20 Feb 2026', '20 Jan 2026']);
    }

    public function test_correction_requires_confirmation_and_rechains_next_reading(): void
    {
        [$landlord, $unit, , , $feb, $mar] = $this->fixture();

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('newReading', '130')
            ->call('review')
            ->assertSet('confirming', true)
            ->assertCount('changes', 2)
            ->call('save') // box not ticked → nothing saved
            ->assertHasErrors('confirmed');

        $this->assertEquals(120, (float) $feb->fresh()->new_reading);

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('newReading', '130')
            ->call('review')
            ->set('confirmed', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $this->assertEquals(30, (float) $feb->fresh()->amount_used);
        $this->assertEquals(130, (float) $mar->fresh()->old_reading);
        $this->assertEquals(10, (float) $mar->fresh()->amount_used);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'utility_usage', 'subject_id' => $feb->id]);
    }

    public function test_reading_lower_than_previous_is_rejected(): void
    {
        [$landlord, $unit, , , $feb] = $this->fixture();

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('newReading', '90')
            ->call('review')
            ->assertHasErrors('newReading')
            ->assertSet('confirming', false);
    }

    public function test_future_and_duplicate_dates_are_rejected(): void
    {
        [$landlord, $unit, , , $feb] = $this->fixture();

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('date', '2026-04-01')
            ->call('review')
            ->assertHasErrors('date')
            ->set('date', '2026-01-20')
            ->call('review')
            ->assertHasErrors('newReading');
    }

    public function test_reading_on_a_pending_invoice_is_locked(): void
    {
        [$landlord, $unit, , , $feb] = $this->fixture();
        $this->billOnInvoice($feb, InvoiceStatus::Pending);

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('newReading', '125')
            ->call('review')
            ->assertHasErrors('newReading');

        $this->assertEquals(120, (float) $feb->fresh()->new_reading);
    }

    public function test_reading_on_a_draft_invoice_reprices_its_line(): void
    {
        [$landlord, $unit, , , $feb] = $this->fixture();
        $line = $this->billOnInvoice($feb, InvoiceStatus::Draft);

        Livewire::actingAs($landlord)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->call('edit', $feb->id)
            ->set('newReading', '130')
            ->call('review')
            ->set('confirmed', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(30, (float) $line->fresh()->quantity);
        $this->assertEquals(4.5, (float) $line->fresh()->amount);
    }

    public function test_another_landlords_room_is_not_accessible(): void
    {
        [, $unit] = $this->fixture();
        $other = $this->makeLandlord();

        Livewire::actingAs($other)
            ->test(UnitReadingHistory::class, ['unitId' => $unit->id])
            ->assertNotFound();
    }

    public function test_utility_rates_screen_opens_history_for_a_room(): void
    {
        [$landlord, $unit, $utility] = $this->fixture();
        ActiveProperty::set($unit->property_id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityRates::class)
            ->assertSee(__('Reading history'))
            ->call('openHistory', $utility->id)
            ->assertSet('historyUtilityId', $utility->id)
            ->assertSet('historyUnitId', $unit->id);
    }

    private function fixture(): array
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Test Property']);
        $unit = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'room_number' => '101', 'room_type' => 'Standard', 'rent_amount' => 500,
        ]);
        $utility = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'name' => 'Electricity', 'billing_type' => BillingType::Metered,
            'rate' => 0.15, 'unit_of_measure' => 'kWh',
        ]);

        $make = fn (string $date, float $old, float $new, float $used) => UtilityUsage::create([
            'unit_id' => $unit->id, 'property_utility_id' => $utility->id,
            'reading_type' => ReadingType::Actual, 'reading_date' => $date, 'recorded_by_id' => $landlord->id,
            'old_reading' => $old, 'new_reading' => $new, 'amount_used' => $used,
        ]);

        return [
            $landlord, $unit, $utility,
            $make('2026-01-20', 100, 100, 0),
            $make('2026-02-20', 100, 120, 20),
            $make('2026-03-20', 120, 140, 20),
        ];
    }

    private function billOnInvoice(UtilityUsage $usage, InvoiceStatus $status): InvoiceLine
    {
        $tenant = User::factory()->create(['email' => 'tenant-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');
        $rental = Rental::create([
            'landlord_id' => $usage->landlord_id,
            'unit_id' => $usage->unit_id,
            'tenant_id' => $tenant->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 500,
            'status' => RentalStatus::Active,
            'start_date' => '2026-01-01',
        ]);

        $invoice = Invoice::withoutGlobalScopes()->create([
            'rental_id' => $rental->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $usage->landlord_id,
            'property_id' => $usage->unit->property_id,
            'invoice_number' => 'INV-'.uniqid(),
            'amount_due' => 3,
            'usd_khr_rate' => 4100,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'issue_date' => '2026-02-21',
            'due_date' => '2026-03-01',
            'payment_status' => $status,
        ]);

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceLineType::Utility,
            'utility_usage_id' => $usage->id,
            'description' => 'Electricity usage',
            'quantity' => 20, 'unit_price' => 0.15, 'amount' => 3,
            'currency' => 'USD', 'amount_usd' => 3, 'amount_khr' => 12300,
            'exchange_rate' => 4100,
        ]);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-utility-usage-'.uniqid().'@example.com',
        ]);
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::firstOrCreate([
            'slug' => 'starter',
        ], [
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
}
