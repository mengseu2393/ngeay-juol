<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the /m mobile shell's tests (one test class per screen).
 * The flag is on by default here; flag tests turn it off themselves. Vite is
 * stubbed — public/build is a build artefact, not in the repository.
 */
abstract class MobileTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutVite();
        ActiveProperty::clear();
        config(['mobile.enabled' => true, 'mobile.testers' => []]);
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An active, subscribed landlord — nothing but the code under test can send them elsewhere. */
    protected function landlord(?string $email = null): User
    {
        $landlord = User::factory()->create([
            'name' => 'Mobile Landlord',
            'email' => $email ?? 'mobile-landlord-'.uniqid().'@example.com',
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

    protected function property(User $landlord, string $name = 'Mobile Property'): Property
    {
        return Property::create(['landlord_id' => $landlord->id, 'name' => $name]);
    }

    /** An occupied room with an active tenancy. */
    protected function rental(User $landlord, Property $property, string $room, string $occupant): Rental
    {
        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'rent_amount' => 300,
            'status' => UnitStatus::Occupied,
        ]);

        $tenant = User::factory()->create(['email' => 'mobile-tenant-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');

        return Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => $occupant,
            'monthly_rent' => 300,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);
    }

    protected function invoice(Rental $rental, string $number, float $amount = 300, InvoiceStatus $status = InvoiceStatus::Pending, ?Carbon $issued = null): Invoice
    {
        $issued ??= now();

        return Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $rental->property_id,
            'landlord_id' => $rental->landlord_id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => $number,
            'amount_due' => $amount,
            'period_start' => $issued->copy()->startOfMonth(),
            'period_end' => $issued->copy()->endOfMonth(),
            'issue_date' => $issued,
            'due_date' => $issued->copy()->addDays(7),
            'payment_status' => $status,
        ]);
    }

    /** Signed in, with $property as the active one. */
    protected function signIn(User $user, ?Property $property = null): static
    {
        $this->actingAs($user);

        if ($property) {
            ActiveProperty::set($property->id);
        }

        return $this;
    }
}
