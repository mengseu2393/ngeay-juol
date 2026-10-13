<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\UnitOverview;
use App\Livewire\PropertyUnitsManager;
use App\Livewire\SimpleRoomList;
use App\Livewire\SimpleUnitDetail;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The unit page is the one place a room is managed end to end. Rules are in
 * services; these pin that the page applies them and gates every action —
 * especially the reset, which records a new STARTING POINT (is_reset) rather
 * than deleting history, so a meter swap never bills a negative / huge jump.
 */
class UnitOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-20 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        ActiveProperty::clear();
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(User $landlord, Unit $unit)
    {
        return Livewire::actingAs($landlord)->test(UnitOverview::class, ['unit' => $unit->id]);
    }

    public function test_the_page_renders_a_room_and_makes_its_property_active(): void
    {
        [$landlord, $property, $vacant, $occupied] = $this->fixture();

        $this->actingAs($landlord)->get(UnitOverview::getUrl(['unit' => $occupied->id]))
            ->assertOk()->assertSee('Sok Dara');

        $this->assertSame($property->id, ActiveProperty::id());
    }

    public function test_another_landlords_room_is_not_accessible(): void
    {
        [, , $vacant] = $this->fixture();

        $this->actingAs($this->makeLandlord())
            ->get(UnitOverview::getUrl(['unit' => $vacant->id]))
            ->assertNotFound();
    }

    public function test_add_agreement_moves_a_tenant_in_with_move_in_readings(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $this->page($landlord, $vacant)
            ->call('openPanel', 'add')
            ->set('occupantName', 'New Tenant')
            ->set('monthlyRent', '450')
            ->set("moveInReadings.{$utility->id}", '500')
            ->call('startAgreement')
            ->assertHasNoErrors()
            ->assertSet('panel', null)
            ->assertSee(__('Agreement created.'))
            // Simple Mode's other screens are mounted on the same page and listen for this.
            ->assertDispatched('tenancy-started');

        $this->assertDatabaseHas('rentals', ['unit_id' => $vacant->id, 'occupant_name' => 'New Tenant', 'status' => RentalStatus::Active->value]);
        $usage = UtilityUsage::where('new_reading', 500)->firstOrFail();
        $this->assertEquals(0, (float) $usage->amount_used); // not billed to the new tenant
    }

    public function test_add_agreement_is_refused_for_an_occupied_room_and_end_for_a_vacant_one(): void
    {
        [$landlord, , $vacant, $occupied] = $this->fixture();

        $this->page($landlord, $occupied)->call('openPanel', 'add')->assertForbidden();
        $this->page($landlord, $vacant)->call('openPanel', 'end')->assertForbidden();
    }

    public function test_ending_an_agreement_needs_confirmation_and_frees_the_room(): void
    {
        [$landlord, , , $occupied] = $this->fixture();

        $c = $this->page($landlord, $occupied)->call('openPanel', 'end')->call('endAgreement')->assertHasErrors('confirmEnd');
        $this->assertSame(UnitStatus::Occupied, $occupied->fresh()->status);

        $c->set('confirmEnd', true)->call('endAgreement')->assertHasNoErrors()->assertSee(__('Agreement ended.'))
            ->assertDispatched('tenancy-ended');

        $this->assertSame(UnitStatus::Available, $occupied->fresh()->status);
        $this->assertDatabaseHas('rentals', ['unit_id' => $occupied->id, 'status' => RentalStatus::Vacated->value]);
    }

    public function test_recording_a_reading_measures_from_the_previous_one(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $this->page($landlord, $vacant)
            ->call('openPanel', 'reading')
            ->set('newReading', '530')
            ->call('saveReading')
            ->assertHasNoErrors();

        $this->assertEquals(50, (float) UtilityUsage::where('new_reading', 530)->value('amount_used'));
    }

    public function test_a_lower_reading_is_rejected(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $this->page($landlord, $vacant)->call('openPanel', 'reading')->set('newReading', '400')->call('saveReading')
            ->assertHasErrors('newReading');
    }

    public function test_reset_needs_confirmation_and_keeps_history(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $c = $this->page($landlord, $vacant)->call('openPanel', 'reset')->set('startingReading', '0')
            ->call('resetReading')->assertHasErrors('confirmReset');
        $this->assertSame(1, UtilityUsage::count());

        $c->set('confirmReset', true)->call('resetReading')->assertHasNoErrors();

        $this->assertSame(2, UtilityUsage::count()); // history kept
        $reset = UtilityUsage::where('is_reset', true)->firstOrFail();
        $this->assertEquals(0, (float) $reset->new_reading);
        $this->assertEquals(0, (float) $reset->amount_used);
    }

    public function test_reset_cannot_be_back_dated_or_overwrite_a_normal_reading(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-03-10', 100, 480);

        $c = $this->page($landlord, $vacant)->call('openPanel', 'reset')->set('confirmReset', true);

        $c->set('date', '2026-03-01')->call('resetReading')->assertHasErrors('date');
        $c->set('date', '2026-03-10')->call('resetReading')->assertHasErrors('date');
        $this->assertSame(1, UtilityUsage::count());
    }

    public function test_the_next_reading_after_a_reset_is_measured_from_it(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $this->page($landlord, $vacant)->call('openPanel', 'reset')
            ->set('startingReading', '0')->set('date', '2026-03-01')->set('confirmReset', true)->call('resetReading');

        $baseline = app(MeterReadingResolver::class)->baselineFor($vacant->id, $utility->id, '2026-03-15', 35.0);

        $this->assertEquals(0, $baseline['old']);
        $this->assertEquals(35, $baseline['amount']); // not a negative / huge jump
    }

    public function test_correcting_a_past_reading_needs_confirmation_and_rechains_the_next(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $feb = $this->reading($vacant, $utility, '2026-02-20', 20, 120);
        $mar = $this->reading($vacant, $utility, '2026-03-10', 20, 140);

        $c = $this->page($landlord, $vacant)->call('edit', $feb->id)->set('editReading', '130')->call('reviewEdit')
            ->assertSet('confirming', true)->assertCount('changes', 2)
            ->call('saveEdit')->assertHasErrors('confirmedEdit');
        $this->assertEquals(120, (float) $feb->fresh()->new_reading);

        $c->set('confirmedEdit', true)->call('saveEdit')->assertHasNoErrors();

        $this->assertEquals(130, (float) $feb->fresh()->new_reading);
        $this->assertEquals(10, (float) $mar->fresh()->amount_used);
    }

    public function test_correcting_a_reading_after_a_reset_is_not_blocked_by_the_lower_value(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        $this->page($landlord, $vacant)->call('openPanel', 'reset')
            ->set('startingReading', '0')->set('date', '2026-03-01')->set('confirmReset', true)->call('resetReading');
        $after = $this->reading($vacant, $utility, '2026-03-10', 20, 20);

        $this->page($landlord, $vacant)->call('edit', $after->id)->set('editReading', '25')->call('reviewEdit')
            ->assertHasNoErrors()->assertSet('confirming', true);
    }

    public function test_simple_mode_has_its_own_room_screen(): void
    {
        [$landlord, $property, $vacant, $occupied] = $this->fixture();

        $this->actingAs($landlord)
            ->get(route('filament.landlord.pages.simple', ['screen' => 'unit', 'unit_id' => $occupied->id]))
            ->assertOk()
            ->assertSee('Sok Dara')
            ->assertSee('simple-unit-detail', false);
    }

    public function test_the_simple_room_screen_runs_the_same_actions(): void
    {
        [$landlord, , $vacant, , $utility] = $this->fixture();
        $this->reading($vacant, $utility, '2026-02-20', 100, 480);

        Livewire::actingAs($landlord)->test(SimpleUnitDetail::class, ['unitId' => $vacant->id])
            ->call('openPanel', 'reading')->set('newReading', '530')->call('saveReading')->assertHasNoErrors();

        $this->assertEquals(50, (float) UtilityUsage::where('new_reading', 530)->value('amount_used'));
    }

    public function test_settings_leads_to_a_simple_units_screen_listing_every_room(): void
    {
        [$landlord, $property, $vacant, $occupied] = $this->fixture();
        ActiveProperty::set($property->id);

        $this->actingAs($landlord)
            ->get(route('filament.landlord.pages.simple', ['screen' => 'settings']))
            ->assertOk()
            ->assertSee('simple-settings-more-units', false);

        $this->actingAs($landlord)
            ->get(route('filament.landlord.pages.simple', ['screen' => 'units']))
            ->assertOk()
            ->assertSee('simple-units', false);

        // Inside Simple Mode each card opens the room screen, not the full-mode page.
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        Livewire::actingAs($landlord)
            ->test(PropertyUnitsManager::class, ['propertyId' => $property->id])
            ->assertSeeHtml(e(route('filament.landlord.pages.simple', ['screen' => 'unit', 'unit_id' => $occupied->id])));
    }

    public function test_the_simple_room_screen_without_a_room_asks_you_to_pick_one(): void
    {
        [$landlord] = $this->fixture();

        Livewire::actingAs($landlord)->test(SimpleUnitDetail::class)->assertSee(__('Pick a room from Rooms to see its details.'));
    }

    public function test_the_simple_room_screen_refuses_another_landlords_room(): void
    {
        [, , $vacant] = $this->fixture();

        $this->expectException(ModelNotFoundException::class); // renders as a 404

        Livewire::actingAs($this->makeLandlord())->test(SimpleUnitDetail::class, ['unitId' => $vacant->id]);
    }

    public function test_room_cards_on_the_simple_rooms_screen_link_to_the_room_screen(): void
    {
        [$landlord, $property, $vacant] = $this->fixture();
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)->test(SimpleRoomList::class)
            ->assertSeeHtml('id="room-open-'.$vacant->id.'"');
    }

    public function test_a_phone_locked_to_simple_mode_can_still_open_the_unit_page(): void
    {
        [$landlord, , $vacant] = $this->fixture();

        $this->actingAs($landlord)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 16; Pixel 10) Mobile Safari/537.36'])
            ->get(UnitOverview::getUrl(['unit' => $vacant->id]))
            ->assertOk();
    }

    private function reading(Unit $unit, PropertyUtility $utility, string $date, float $used, float $new): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $unit->id, 'property_utility_id' => $utility->id,
            'reading_type' => ReadingType::Actual, 'reading_date' => $date,
            'old_reading' => $new - $used, 'new_reading' => $new, 'amount_used' => $used,
            'recorded_by_id' => $unit->landlord_id,
        ]);
    }

    private function fixture(): array
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Test Property']);
        $vacant = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'room_number' => '101',
            'room_type' => 'Standard', 'rent_amount' => 500, 'status' => UnitStatus::Available,
        ]);
        $occupied = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'room_number' => '102',
            'room_type' => 'Standard', 'rent_amount' => 500, 'status' => UnitStatus::Occupied,
        ]);
        $tenant = User::factory()->create(['email' => 't-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');
        Rental::create([
            'landlord_id' => $landlord->id, 'unit_id' => $occupied->id, 'tenant_id' => $tenant->id,
            'occupant_name' => 'Sok Dara', 'monthly_rent' => 500, 'status' => RentalStatus::Active,
            'start_date' => '2026-01-01',
        ]);
        $utility = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 0.15, 'unit_of_measure' => 'kWh',
        ]);

        return [$landlord, $property, $vacant, $occupied, $utility];
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
