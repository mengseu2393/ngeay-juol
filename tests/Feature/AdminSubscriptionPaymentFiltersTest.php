<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\SubscriptionPaymentsTableWidget;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSubscriptionPaymentFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_subscription_payment_resource_still_registers_on_the_admin_route(): void
    {
        $this->assertStringEndsWith('/admin/subscription-payments', SubscriptionPaymentResource::getUrl('index'));
    }

    public function test_subscription_payments_are_reachable_as_a_tab_of_the_merged_subscriptions_page(): void
    {
        $this->actingAs($this->superAdmin()->fresh());

        $this->get(SubscriptionResource::getUrl('index', ['tab' => ListSubscriptions::TAB_PAYMENTS]))
            ->assertOk()
            ->assertSeeLivewire(SubscriptionPaymentsTableWidget::class);
    }

    public function test_the_old_subscription_payments_url_redirects_to_the_merged_page(): void
    {
        $this->actingAs($this->superAdmin()->fresh());

        $this->get(SubscriptionPaymentResource::getUrl('index'))
            ->assertRedirect(SubscriptionResource::getUrl('index', ['tab' => ListSubscriptions::TAB_PAYMENTS]));
    }

    public function test_subscription_payments_no_longer_have_their_own_navigation_entry(): void
    {
        $this->actingAs($this->superAdmin()->fresh());

        $this->assertFalse(SubscriptionPaymentResource::shouldRegisterNavigation());
        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
    }

    public function test_status_filter_limits_records_to_the_selected_subscription_payment_status(): void
    {
        $staff = $this->superAdmin();
        $landlord = $this->landlord('status');
        $plan = $this->plan('status');

        $subscription = $this->subscription($landlord, $plan);

        $succeeded = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subDays(2),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-SUCCESS',
        ]);

        $failed = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Failed,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subDays(1),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-FAILED',
        ]);

        $this->actingAs($staff);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('status', SubscriptionPaymentStatus::Succeeded->value)
            ->assertCanSeeTableRecords([$succeeded])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_method_filter_limits_records_to_the_selected_payment_method(): void
    {
        $staff = $this->superAdmin();
        $landlord = $this->landlord('method');
        $plan = $this->plan('method');

        $subscription = $this->subscription($landlord, $plan);

        $cash = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subDays(4),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-CASH',
        ]);

        $bank = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::BankTransfer,
            'paid_at' => now()->subDays(3),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-BANK',
        ]);

        $this->actingAs($staff);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('method', PaymentMethod::Cash->value)
            ->assertCanSeeTableRecords([$cash])
            ->assertCanNotSeeTableRecords([$bank]);
    }

    public function test_landlord_filter_limits_records_to_the_selected_landlord(): void
    {
        $staff = $this->superAdmin();
        $landlordOne = $this->landlord('one');
        $landlordTwo = $this->landlord('two');
        $plan = $this->plan('landlord');

        $subscriptionOne = $this->subscription($landlordOne, $plan);
        $subscriptionTwo = $this->subscription($landlordTwo, $plan);

        $owned = $this->payment($subscriptionOne, $landlordOne, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subDays(5),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-OWNED',
        ]);

        $other = $this->payment($subscriptionTwo, $landlordTwo, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subDays(6),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-OTHER',
        ]);

        $this->actingAs($staff);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('landlord_id', $landlordOne->id)
            ->assertCanSeeTableRecords([$owned])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_payment_date_filter_supports_preset_and_custom_periods(): void
    {
        $staff = $this->superAdmin();
        $landlord = $this->landlord('date');
        $plan = $this->plan('date');

        $subscription = $this->subscription($landlord, $plan);

        $thisMonth = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->startOfMonth()->addDays(2),
            'covers_from' => now()->startOfMonth(),
            'covers_to' => now()->endOfMonth(),
            'receipt_number' => 'RCPT-THIS-MONTH',
        ]);

        $lastMonth = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->subMonth()->startOfMonth()->addDays(2),
            'covers_from' => now()->subMonth()->startOfMonth(),
            'covers_to' => now()->subMonth()->endOfMonth(),
            'receipt_number' => 'RCPT-LAST-MONTH',
        ]);

        $outsideCustomWindow = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->addMonth()->startOfMonth()->addDays(5),
            'covers_from' => now()->addMonth()->startOfMonth(),
            'covers_to' => now()->addMonth()->endOfMonth(),
            'receipt_number' => 'RCPT-OUTSIDE-CUSTOM',
        ]);

        $this->actingAs($staff);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('paid_at', ['period' => 'this_month'])
            ->assertCanSeeTableRecords([$thisMonth])
            ->assertCanNotSeeTableRecords([$lastMonth, $outsideCustomWindow]);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('paid_at', ['period' => 'last_month'])
            ->assertCanSeeTableRecords([$lastMonth])
            ->assertCanNotSeeTableRecords([$thisMonth, $outsideCustomWindow]);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('paid_at', [
                'period' => 'custom',
                'from' => now()->subMonth()->startOfMonth()->toDateString(),
                'until' => now()->startOfMonth()->addDays(10)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$thisMonth, $lastMonth])
            ->assertCanNotSeeTableRecords([$outsideCustomWindow]);
    }

    public function test_coverage_period_filter_uses_range_overlap_logic(): void
    {
        $staff = $this->superAdmin();
        $landlord = $this->landlord('coverage');
        $plan = $this->plan('coverage');

        $subscription = $this->subscription($landlord, $plan);

        $overlapping = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->startOfMonth()->addDays(1),
            'covers_from' => now()->subMonth()->addDays(20),
            'covers_to' => now()->addDays(10),
            'receipt_number' => 'RCPT-OVERLAP',
        ]);

        $outside = $this->payment($subscription, $landlord, $plan, [
            'status' => SubscriptionPaymentStatus::Succeeded,
            'method' => PaymentMethod::Cash,
            'paid_at' => now()->startOfMonth()->addDays(3),
            'covers_from' => now()->addMonth()->startOfMonth(),
            'covers_to' => now()->addMonth()->endOfMonth(),
            'receipt_number' => 'RCPT-NO-OVERLAP',
        ]);

        $this->actingAs($staff);

        Livewire::test(SubscriptionPaymentsTableWidget::class)
            ->filterTable('coverage_period', [
                'coverage_from' => now()->startOfMonth()->toDateString(),
                'coverage_until' => now()->startOfMonth()->addDays(20)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$overlapping])
            ->assertCanNotSeeTableRecords([$outside]);
    }

    protected function superAdmin(): User
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole('super_admin');

        return $user;
    }

    protected function landlord(string $suffix): User
    {
        $user = User::create([
            'name' => 'Landlord '.ucfirst($suffix),
            'email' => 'landlord-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole('landlord');

        return $user;
    }

    protected function plan(string $suffix): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Plan '.ucfirst($suffix),
            'slug' => 'plan-'.$suffix.'-'.uniqid(),
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 25,
            'currency' => 'USD',
            'trial_days' => 0,
            'grace_days' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function subscription(User $landlord, SubscriptionPlan $plan): Subscription
    {
        return Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => $plan->price,
            'currency' => 'USD',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonth()->endOfMonth(),
            'auto_renew' => true,
        ]);
    }

    /** @param array{status: SubscriptionPaymentStatus, method: PaymentMethod, paid_at: \Carbon\CarbonInterface|null, covers_from: \Carbon\CarbonInterface, covers_to: \Carbon\CarbonInterface, receipt_number: string} $overrides */
    protected function payment(Subscription $subscription, User $landlord, SubscriptionPlan $plan, array $overrides): SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScopes()->create(array_merge([
            'subscription_id' => $subscription->id,
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'amount' => 25,
            'currency' => 'USD',
            'gateway' => 'manual',
            'note' => null,
        ], $overrides));
    }
}
