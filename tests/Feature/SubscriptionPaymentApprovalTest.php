<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionPaymentResource\Pages\ListSubscriptionPayments;
use App\Filament\Tables\Actions\ApproveSubscriptionPaymentAction;
use App\Filament\Widgets\AdminPendingPaymentsTableWidget;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A stand-in for the resource's list page.
 *
 * {@see ListSubscriptionPayments} redirects to the merged subscriptions page on
 * mount, which leaves a Livewire test with no component to call actions on.
 * Everything that matters here — the resource, and therefore
 * {@see SubscriptionPaymentResource::table()} with its
 * row actions — is inherited untouched; only the redirect is dropped.
 */
class SubscriptionPaymentsListHarness extends ListSubscriptionPayments
{
    public function mount(): void {}
}

/**
 * Settling a subscription payment used to exist in exactly one place — the
 * Renewals page's "Awaiting approval" table — while the payments list, the
 * obvious place to go looking, offered only View/Edit/Delete. Approving by
 * hand-editing `status` skips everything {@see SubscriptionService::renew()}
 * does beyond that one column: the period move, the history entry and clearing
 * any suspension.
 *
 * These tests pin the two surfaces together. Both now mount the same
 * {@see ApproveSubscriptionPaymentAction} / RejectSubscriptionPaymentAction, and
 * the point of the first test is that "same action class" means "same rows in
 * the database afterwards", not merely "same label".
 */
class SubscriptionPaymentApprovalTest extends TestCase
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

    public function test_approving_from_the_resource_matches_approving_from_the_widget(): void
    {
        $this->actingAs($this->superAdmin());

        [$viaWidget, $viaResource] = [$this->pendingRenewal('widget'), $this->pendingRenewal('resource')];

        Livewire::test(AdminPendingPaymentsTableWidget::class)
            ->callTableAction('approve', $viaWidget['payment'])
            ->assertHasNoTableActionErrors();

        Livewire::test(SubscriptionPaymentsListHarness::class)
            ->callTableAction('approve', $viaResource['payment'])
            ->assertHasNoTableActionErrors();

        foreach ([$viaWidget, $viaResource] as $case) {
            $payment = $case['payment']->refresh();
            $subscription = $case['subscription']->refresh();

            $this->assertSame(SubscriptionPaymentStatus::Succeeded, $payment->status);
            $this->assertNotNull($payment->paid_at);

            // renew() moves the period end to the payment's coverage and un-suspends.
            $this->assertSame(SubscriptionStatus::Active, $subscription->status);
            $this->assertSame('2026-09-01', $subscription->ends_at->toDateString());
            $this->assertNull($subscription->suspended_at);

            $this->assertDatabaseHas('subscription_histories', [
                'subscription_id' => $subscription->id,
                'action' => SubscriptionAction::Renewed->value,
                'period_end' => '2026-09-01 00:00:00',
            ]);
        }

        // Settling never duplicates the pending row — renew() finds and updates it.
        $this->assertSame(1, SubscriptionPayment::withoutGlobalScopes()
            ->where('subscription_id', $viaResource['subscription']->id)
            ->count());

        $this->assertSame(
            SubscriptionHistory::withoutGlobalScopes()->where('subscription_id', $viaWidget['subscription']->id)->count(),
            SubscriptionHistory::withoutGlobalScopes()->where('subscription_id', $viaResource['subscription']->id)->count(),
        );
    }

    public function test_rejecting_from_the_resource_fails_the_payment_and_keeps_the_reason(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $case = $this->pendingRenewal('reject');

        Livewire::test(SubscriptionPaymentsListHarness::class)
            ->callTableAction('reject', $case['payment'], ['reason' => 'Bank statement shows nothing'])
            ->assertHasNoTableActionErrors();

        $payment = $case['payment']->refresh();

        $this->assertSame(SubscriptionPaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('Bank statement shows nothing', $payment->note);
        $this->assertStringContainsString($admin->name, $payment->note);

        // The subscription is untouched: a rejection is a record, not a renewal.
        $this->assertSame('2026-08-01', $case['subscription']->refresh()->ends_at->toDateString());
        $this->assertDatabaseMissing('subscription_histories', [
            'subscription_id' => $case['subscription']->id,
            'action' => SubscriptionAction::Renewed->value,
        ]);
    }

    public function test_rejection_reason_is_required(): void
    {
        $this->actingAs($this->superAdmin());

        $case = $this->pendingRenewal('reason');

        Livewire::test(SubscriptionPaymentsListHarness::class)
            ->callTableAction('reject', $case['payment'], ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(SubscriptionPaymentStatus::Pending, $case['payment']->refresh()->status);
    }

    /**
     * The stale-row case the widget's comment was written for.
     *
     * renew() sets the period end to the payment's `covers_to` outright — it does
     * not take the later of the two — so approving a payment that covers a period
     * the subscription has already moved past drags the end date *backwards* and
     * shortens the landlord's access. That is deliberately warned about in the
     * confirmation modal rather than blocked, because a late-settled payment for
     * an earlier period is a real thing an admin sometimes has to record; the
     * requirement is only that it can never happen by surprise.
     */
    public function test_approval_modal_warns_when_a_stale_payment_would_shorten_the_subscription(): void
    {
        $this->actingAs($this->superAdmin());

        $case = $this->pendingRenewal('stale');

        // The subscription has since been renewed well past what this row covers.
        $case['subscription']->forceFill(['ends_at' => Carbon::parse('2026-11-01')])->save();
        $payment = $case['payment']->fresh(['subscription']);

        $summary = ApproveSubscriptionPaymentAction::approvalSummary($payment);

        $this->assertStringContainsString('01 Nov 2026', $summary);
        $this->assertStringContainsString('61', $summary, 'The modal must name how many days would be lost.');

        // Same warning on both surfaces, because both mount the same action class.
        foreach ([AdminPendingPaymentsTableWidget::class, SubscriptionPaymentsListHarness::class] as $component) {
            Livewire::test($component)
                ->mountTableAction('approve', $payment)
                ->assertSee('01 Nov 2026');
        }
    }

    public function test_approve_and_reject_are_hidden_for_a_payment_that_is_not_pending(): void
    {
        $this->actingAs($this->superAdmin());

        $case = $this->pendingRenewal('settled');
        $case['payment']->forceFill(['status' => SubscriptionPaymentStatus::Succeeded])->save();

        Livewire::test(SubscriptionPaymentsListHarness::class)
            ->assertTableActionHidden('approve', $case['payment'])
            ->assertTableActionHidden('reject', $case['payment']);
    }

    public function test_a_pending_payment_still_offers_both_actions_on_the_resource(): void
    {
        $this->actingAs($this->superAdmin());

        $case = $this->pendingRenewal('visible');

        Livewire::test(SubscriptionPaymentsListHarness::class)
            ->assertTableActionVisible('approve', $case['payment'])
            ->assertTableActionVisible('reject', $case['payment']);
    }

    /**
     * A suspended subscription whose next period is sitting unapproved — the shape
     * of every row in the "Awaiting approval" queue.
     *
     * @return array{subscription: Subscription, payment: SubscriptionPayment, landlord: User}
     */
    protected function pendingRenewal(string $suffix): array
    {
        $landlord = User::create([
            'name' => 'Landlord '.ucfirst($suffix),
            'email' => 'landlord-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::create([
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

        $subscription = Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Suspended,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 25,
            'currency' => 'USD',
            'starts_at' => Carbon::parse('2026-07-01'),
            'ends_at' => Carbon::parse('2026-08-01'),
            'suspended_at' => Carbon::parse('2026-07-03'),
            'suspension_reason' => 'Unpaid',
            'auto_renew' => true,
        ]);

        $payment = SubscriptionPayment::withoutGlobalScopes()->create([
            'subscription_id' => $subscription->id,
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'amount' => 25,
            'currency' => 'USD',
            'method' => PaymentMethod::BankTransfer,
            'status' => SubscriptionPaymentStatus::Pending,
            'covers_from' => Carbon::parse('2026-08-01'),
            'covers_to' => Carbon::parse('2026-09-01'),
            'gateway' => 'manual',
            'gateway_ref' => 'REF-'.strtoupper($suffix),
            'receipt_number' => 'RCPT-'.strtoupper($suffix),
        ]);

        return ['subscription' => $subscription, 'payment' => $payment, 'landlord' => $landlord];
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
}
