<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The properties table forces its Split/Stack columns into a card layout
 * (instead of the md:flex-row split) at any viewport width when reached from
 * Simple Mode (?from=simple) — mirrors PropertyUtilityResource's table.
 */
class PropertyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
    }

    public function test_from_simple_forces_the_table_into_card_view(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Card Check Property']);

        $response = $this->actingAs($landlord)->get('/app/properties?from=simple');

        $response->assertSuccessful();
        $response->assertSee('rw-force-card-split', false);
    }

    public function test_without_from_simple_the_table_stays_a_plain_split(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Plain Split Property']);

        $response = $this->actingAs($landlord)->get('/app/properties');

        $response->assertSuccessful();
        $response->assertDontSee('rw-force-card-split', false);
    }

    /**
     * The View row action defaults to navigating to the full ViewProperty page
     * (since 'view' is a registered resource page) — it should pop up as a
     * modal instead, using PropertyResource::infolist().
     */
    public function test_view_action_opens_as_a_modal_instead_of_navigating(): void
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Modal Check Property']);

        $response = $this->actingAs($landlord)->get('/app/properties');
        $response->assertSuccessful();

        // The View action mounts a modal action rather than rendering a link
        // to the ViewProperty page — the record's own row-click link is
        // untouched (unrelated to this row action) and would still be a link.
        $this->assertStringContainsString('mountTableAction(&#039;view&#039;, &#039;'.$property->id.'&#039;)', $response->getContent());
    }

    private function makeLandlord(): User
    {
        $landlord = User::create([
            'name' => 'Test Landlord',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
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
}
