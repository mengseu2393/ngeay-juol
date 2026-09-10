<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\PropertyType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\LandlordResource\Pages\CreateLandlord;
use App\Filament\Resources\LandlordResource\Pages\ListLandlords;
use App\Filament\Resources\LandlordResource\Pages\ViewLandlord;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Provisioning a landlord from /admin must produce a landlord who can actually
 * use the product. Before this suite, CreateLandlord only assigned the role, and
 * the subscription had to be created from a second, entirely separate resource —
 * so the common path produced an account that SubscriptionService::effectiveAccess()
 * reported as Revoked and EnsureActiveSubscription logged straight back out.
 */
class LandlordProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creating_a_landlord_assigns_the_role_and_a_subscription_snapshotting_the_plan(): void
    {
        $this->actingAs($this->superAdmin());
        $plan = $this->plan();

        Livewire::test(CreateLandlord::class)
            ->fillForm([
                'first_name' => 'Sok',
                'last_name' => 'Dara',
                'email' => 'sok.dara@example.com',
                'password' => 'password',
                'status' => UserStatus::Active->value,
                'subscription_plan_id' => $plan->id,
                'subscription_auto_renew' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $landlord = User::where('email', 'sok.dara@example.com')->firstOrFail();

        $this->assertSame('Sok Dara', $landlord->name);
        $this->assertTrue($landlord->hasRole('landlord'));

        $subscription = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $landlord->getKey())
            ->firstOrFail();

        // Plan terms are snapshotted onto the subscription at assignment time —
        // editing the plan template later must not change this record.
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertEquals(49, $subscription->price);
        $this->assertSame(25, $subscription->max_units);
        $this->assertSame(PlanInterval::Monthly, $subscription->interval);
        $this->assertSame(PlanBillingModel::Flat, $subscription->billing_model);
        $this->assertSame(['reports' => true], $subscription->features);

        // trial_days = 14 from the plan, grace_days = 7.
        $this->assertSame(SubscriptionStatus::Trial, $subscription->status);
        $this->assertSame('2026-09-24', $subscription->trial_ends_at->toDateString());
        $this->assertSame('2026-09-25', $subscription->starts_at->toDateString());
        $this->assertSame('2026-10-25', $subscription->ends_at->toDateString());
        $this->assertSame('2026-11-01', $subscription->grace_ends_at->toDateString());
    }

    /**
     * THE regression this feature exists for.
     *
     * A Subscription row is the only thing standing between a freshly created
     * landlord and an immediate logout: effectiveAccess() returns Revoked when no
     * row exists, and EnsureActiveSubscription turns Revoked into
     * `auth()->logout()` + redirect on the landlord panel. Assert the end state
     * (Full access), not just "a subscription row exists" — a row with the wrong
     * dates would still lock the customer out.
     */
    public function test_a_landlord_created_from_the_admin_panel_has_full_subscription_access(): void
    {
        $this->actingAs($this->superAdmin());
        $plan = $this->plan();

        Livewire::test(CreateLandlord::class)
            ->fillForm([
                'first_name' => 'Chan',
                'last_name' => 'Nary',
                'email' => 'chan.nary@example.com',
                'password' => 'password',
                'status' => UserStatus::Active->value,
                'subscription_plan_id' => $plan->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $landlord = User::where('email', 'chan.nary@example.com')->firstOrFail();

        $this->assertSame(
            SubscriptionAccess::Full,
            SubscriptionService::effectiveAccess($landlord),
        );
    }

    public function test_a_trial_days_override_replaces_the_plan_default(): void
    {
        $this->actingAs($this->superAdmin());
        $plan = $this->plan();

        Livewire::test(CreateLandlord::class)
            ->fillForm([
                'first_name' => 'Vuth',
                'email' => 'vuth@example.com',
                'password' => 'password',
                'status' => UserStatus::Active->value,
                'subscription_plan_id' => $plan->id,
                'subscription_trial_days' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscription = Subscription::withoutGlobalScopes()
            ->where('landlord_id', User::where('email', 'vuth@example.com')->value('id'))
            ->firstOrFail();

        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    /**
     * The plan Select is required, so the form must refuse rather than fall back to
     * creating an unusable landlord.
     */
    public function test_a_landlord_cannot_be_created_without_a_plan(): void
    {
        $this->actingAs($this->superAdmin());
        $this->plan();

        Livewire::test(CreateLandlord::class)
            ->fillForm([
                'first_name' => 'No',
                'last_name' => 'Plan',
                'email' => 'no.plan@example.com',
                'password' => 'password',
                'status' => UserStatus::Active->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['subscription_plan_id']);

        $this->assertDatabaseMissing('users', ['email' => 'no.plan@example.com']);
    }

    public function test_the_missing_subscription_filter_lists_only_landlords_without_one(): void
    {
        $this->actingAs($this->superAdmin());

        $plan = $this->plan();
        $covered = $this->landlord('covered');
        SubscriptionService::assign($covered, $plan);
        $uncovered = $this->landlord('uncovered');

        Livewire::test(ListLandlords::class)
            ->assertCanSeeTableRecords([$covered, $uncovered])
            ->filterTable('missing_subscription', true)
            ->assertCanSeeTableRecords([$uncovered])
            ->assertCanNotSeeTableRecords([$covered]);
    }

    public function test_the_table_flags_a_landlord_with_no_subscription(): void
    {
        $this->actingAs($this->superAdmin());

        $plan = $this->plan();
        $covered = $this->landlord('covered');
        SubscriptionService::assign($covered, $plan);
        $uncovered = $this->landlord('uncovered');

        $this->assertSame(__('No subscription'), LandlordResource::subscriptionLabel($uncovered->fresh()));
        $this->assertStringContainsString($plan->name, LandlordResource::subscriptionLabel($covered->fresh()));
    }

    /**
     * The customer-360 infolist is the screen a support agent reads during a call,
     * so it must render for the awkward case too: a landlord with no subscription,
     * no portfolio and no invoices (every aggregate is null/zero).
     */
    public function test_the_customer_view_renders_with_and_without_a_subscription(): void
    {
        $this->actingAs($this->support());

        $covered = $this->landlord('covered');
        SubscriptionService::assign($covered, $this->plan());
        $uncovered = $this->landlord('uncovered');

        foreach ([$covered, $uncovered] as $record) {
            Livewire::test(ViewLandlord::class, ['record' => $record->getKey()])
                ->assertSuccessful();
        }

        $this->assertSame('0 / 25', LandlordResource::unitCapUsage($covered->fresh()));
        $this->assertSame('0 / '.__('Unlimited'), LandlordResource::unitCapUsage($uncovered->fresh()));
        $this->assertSame(
            ['usd' => 0.0, 'khr' => 0.0, 'open' => 0, 'overdue' => 0],
            LandlordResource::receivables($uncovered->fresh()),
        );
    }

    /**
     * The portfolio/receivables aggregates are the numbers a support agent quotes
     * back to a customer, so pin the exact arithmetic: Draft, Paid and Cancelled
     * invoices are NOT receivables, and legacy rows with no `total_usd` fall back
     * to `amount_due` the same way Invoice::resolvePaymentStatus() does.
     */
    public function test_the_customer_view_reports_portfolio_counts_and_outstanding_receivables(): void
    {
        $this->actingAs($this->superAdmin());

        $landlord = $this->landlord('portfolio');
        SubscriptionService::assign($landlord, $this->plan());
        $tenant = $this->tenant();

        $property = Property::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->getKey(),
            'name' => 'Riverside Block',
            'property_type' => PropertyType::cases()[0],
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->getKey(),
            'room_number' => 'A-101',
            'room_type' => 'Standard',
            'rent_amount' => 300,
            'status' => UnitStatus::Occupied,
        ]);

        $rental = Rental::withoutGlobalScopes()->create([
            'unit_id' => $unit->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->getKey(),
            'tenant_id' => $tenant->getKey(),
            'occupant_name' => 'Tenant Person',
            'monthly_rent' => 300,
            'status' => RentalStatus::Active,
            'start_date' => '2026-08-01',
            'next_invoice_date' => '2026-09-01',
        ]);

        $invoice = fn (string $number, float $due, InvoiceStatus $status) => Invoice::withoutGlobalScopes()->create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->getKey(),
            'tenant_id' => $tenant->getKey(),
            'invoice_number' => $number,
            'amount_due' => $due,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-10',
            'payment_status' => $status,
        ]);

        $invoice('INV-PENDING', 300, InvoiceStatus::Pending);
        $invoice('INV-OVERDUE', 150, InvoiceStatus::Overdue);
        $invoice('INV-PAID', 999, InvoiceStatus::Paid);
        $invoice('INV-DRAFT', 888, InvoiceStatus::Draft);
        $invoice('INV-CANCELLED', 777, InvoiceStatus::Cancelled);

        $this->assertSame(
            ['usd' => 450.0, 'khr' => 0.0, 'open' => 2, 'overdue' => 1],
            LandlordResource::receivables($landlord->fresh()),
        );

        $this->assertSame('1 / 25', LandlordResource::unitCapUsage($landlord->fresh()));

        $counted = LandlordResource::getEloquentQuery()->whereKey($landlord->getKey())->firstOrFail();
        $this->assertSame(1, $counted->properties_count);
        $this->assertSame(1, $counted->units_count);
        $this->assertSame(1, $counted->active_rentals_count);

        Livewire::test(ViewLandlord::class, ['record' => $landlord->getKey()])->assertSuccessful();
    }

    public function test_support_can_list_landlords_but_cannot_create_them(): void
    {
        $plan = $this->plan();
        $landlord = $this->landlord('visible');
        SubscriptionService::assign($landlord, $plan);

        $this->actingAs($this->support());

        $this->assertTrue(LandlordResource::canAccess());
        $this->assertTrue(LandlordResource::shouldRegisterNavigation());
        $this->assertFalse(LandlordResource::canCreate());
        $this->assertFalse(LandlordResource::canEdit($landlord));
        $this->assertFalse(LandlordResource::canDelete($landlord));

        Livewire::test(ListLandlords::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$landlord])
            ->assertActionHidden('create');
    }

    public function test_a_super_admin_keeps_write_access_to_the_directory(): void
    {
        $landlord = $this->landlord('writable');

        $this->actingAs($this->superAdmin());

        $this->assertTrue(LandlordResource::canCreate());
        $this->assertTrue(LandlordResource::canEdit($landlord));
        $this->assertTrue(LandlordResource::canDelete($landlord));
    }

    /**
     * Deletion copy must match reality: every landlord-owned table declares
     * `landlord_id ... restrictOnDelete()`, so a landlord that still owns
     * properties is gated out of the delete action entirely rather than being
     * offered a delete that would either orphan or blow up.
     */
    public function test_a_landlord_that_still_owns_properties_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin());

        $withProperty = $this->landlord('with-property');
        Property::withoutGlobalScopes()->create([
            'landlord_id' => $withProperty->getKey(),
            'name' => 'Sunrise Apartments',
            'property_type' => PropertyType::cases()[0],
        ]);

        $withoutProperty = $this->landlord('without-property');

        $this->assertTrue(LandlordResource::ownsProperties($withProperty->fresh()));
        $this->assertFalse(LandlordResource::ownsProperties($withoutProperty->fresh()));

        Livewire::test(ListLandlords::class)
            ->assertTableActionDisabled('delete', $withProperty)
            ->assertTableActionEnabled('delete', $withoutProperty);
    }

    // -----------------------------------------------------------------
    // Fixtures (explicit Model::create — this app has no factories but User)
    // -----------------------------------------------------------------

    protected function superAdmin(): User
    {
        return $this->staff('super_admin');
    }

    protected function support(): User
    {
        return $this->staff('support');
    }

    protected function staff(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Staff',
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole($role);

        return $user;
    }

    protected function landlord(string $suffix): User
    {
        $user = User::create([
            'name' => 'Landlord '.$suffix,
            'email' => 'landlord-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole('landlord');

        return $user;
    }

    protected function tenant(): User
    {
        $user = User::create([
            'name' => 'Tenant Person',
            'email' => 'tenant-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole('tenant');

        return $user;
    }

    protected function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Growth',
            'slug' => 'growth-'.uniqid(),
            'billing_model' => PlanBillingModel::Flat,
            'interval' => PlanInterval::Monthly,
            'price' => 49,
            'unit_price' => 0,
            'max_units' => 25,
            'max_properties' => 5,
            'trial_days' => 14,
            'grace_days' => 7,
            'features' => ['reports' => true],
            'currency' => 'USD',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
