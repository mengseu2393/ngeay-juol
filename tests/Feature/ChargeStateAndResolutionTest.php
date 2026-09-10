<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Models\BillingRunChargeDecision;
use App\Models\ChargeDefinition;
use App\Models\ChargeRule;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;
use App\Services\ChargeRuleResolver;
use App\Services\ExchangeRateService;
use App\Services\InvoiceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChargeStateAndResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Property $property;

    protected PropertySetting $settings;

    protected Unit $unit1;

    protected Unit $unit2;

    protected Rental $rental1;

    protected Rental $rental2;

    protected PropertyUtility $utility;

    protected ChargeDefinition $definition;

    protected InvoiceBuilderService $builder;

    protected ChargeRuleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(InvoiceBuilderService::class);
        $this->resolver = app(ChargeRuleResolver::class);

        $this->landlord = User::create([
            'name' => 'Landlord',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->tenant = User::create([
            'name' => 'Tenant',
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->property = Property::create([
            'landlord_id' => $this->landlord->id,
            'name' => 'Rose Villa',
        ]);

        $this->settings = PropertySetting::create([
            'property_id' => $this->property->id,
            'currency' => 'USD',
            'usd_khr_exchange_rate' => 4000.0,
            'exchange_rate_source' => 'manual',
            'exchange_rate_date' => now()->toDateString(),
        ]);

        $this->unit1 = Unit::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 100.00,
            'rent_currency' => 'USD',
        ]);

        $this->unit2 = Unit::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 120.00,
            'rent_currency' => 'USD',
        ]);

        $this->rental1 = Rental::create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit1->id,
            'monthly_rent' => 100.00,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 100.00,
            'security_deposit_currency' => 'USD',
            'start_date' => now()->toDateString(),
            'status' => RentalStatus::Active,
        ]);

        $this->rental2 = Rental::create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'unit_id' => $this->unit2->id,
            'monthly_rent' => 120.00,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 120.00,
            'security_deposit_currency' => 'USD',
            'start_date' => now()->toDateString(),
            'status' => RentalStatus::Active,
        ]);

        $this->definition = ChargeDefinition::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'name' => 'Wifi Fee',
            'category' => 'internet',
            'billing_type' => 'flat',
            'default_amount' => 10.00,
            'default_currency' => 'USD',
        ]);

        $this->utility = PropertyUtility::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'charge_definition_id' => $this->definition->id,
            'name' => 'Wifi Fee',
            'billing_type' => BillingType::Flat,
            'rate' => 10.00,
            'currency' => 'USD',
        ]);
    }

    public function test_property_wide_normal_charge_applies_to_all_rooms(): void
    {
        // 1. Create a property-wide rule (state = normal)
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'property',
            'scope_id' => $this->property->id,
            'state' => 'normal',
        ]);

        // 2. Resolve for rental 1 and rental 2
        $decision1 = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
        ]);
        $decision2 = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental2->id,
        ]);

        $this->assertEquals('normal', $decision1['effective_state']);
        $this->assertEquals(10.00, $decision1['amount']);
        $this->assertEquals('USD', $decision1['currency']);
        $this->assertTrue($decision1['should_create_line']);

        $this->assertEquals('normal', $decision2['effective_state']);
        $this->assertEquals(10.00, $decision2['amount']);
        $this->assertEquals('USD', $decision2['currency']);
        $this->assertTrue($decision2['should_create_line']);
    }

    public function test_unit_level_not_applicable_overrides_property_normal(): void
    {
        // 1. Property rule: normal
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'property',
            'scope_id' => $this->property->id,
            'state' => 'normal',
        ]);

        // 2. Unit 1 override: not_applicable
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'unit',
            'scope_id' => $this->unit1->id,
            'state' => 'not_applicable',
        ]);

        // 3. Resolve for rental 1 (unit 1) -> not_applicable
        $decision1 = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
        ]);

        // 4. Resolve for rental 2 (unit 2) -> normal
        $decision2 = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental2->id,
        ]);

        $this->assertEquals('not_applicable', $decision1['effective_state']);
        $this->assertFalse($decision1['should_create_line']);

        $this->assertEquals('normal', $decision2['effective_state']);
        $this->assertTrue($decision2['should_create_line']);
    }

    public function test_rental_level_free_overrides_unit_property_normal(): void
    {
        // 1. Property rule: normal
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'property',
            'scope_id' => $this->property->id,
            'state' => 'normal',
        ]);

        // 2. Unit 1 rule: normal
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'unit',
            'scope_id' => $this->unit1->id,
            'state' => 'normal',
        ]);

        // 3. Rental 1 override: free
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'free',
        ]);

        // 4. Resolve for rental 1 -> free
        $decision = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
        ]);

        $this->assertEquals('free', $decision['effective_state']);
        $this->assertEquals(0.0, $decision['amount']);
        $this->assertTrue($decision['should_create_line']);
    }

    public function test_invoice_run_waived_overrides_all_persistent_rules(): void
    {
        // Rental 1 persistent rule: normal
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'normal',
        ]);

        // Resolve with manual override waived
        $decision = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
            'manual_state' => 'waived',
            'manual_reason' => 'Special promotion',
        ]);

        $this->assertEquals('waived', $decision['effective_state']);
        $this->assertEquals(0.0, $decision['amount']);
        $this->assertEquals('Special promotion', $decision['reason']);
        $this->assertTrue($decision['should_create_line']);
    }

    public function test_free_charge_creates_zero_invoice_line_with_free_label(): void
    {
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'free',
        ]);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
        ]);

        $line = $invoice->lines()->first();
        $this->assertEquals(0.0, $line->amount);
        $this->assertStringContainsString('Free', $line->description);
        $this->assertEquals('free', $line->charge_state);
        $this->assertEquals('Free', $line->charge_state_label);
    }

    public function test_waived_charge_creates_zero_invoice_line_with_waived_label_and_reason(): void
    {
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'waived',
            'reason' => 'Late setup',
        ]);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
        ]);

        $line = $invoice->lines()->first();
        $this->assertEquals(0.0, $line->amount);
        $this->assertStringContainsString('Waived', $line->description);
        $this->assertStringContainsString('Late setup', $line->description);
        $this->assertEquals('waived', $line->charge_state);
        $this->assertEquals('Late setup', $line->charge_state_reason);
    }

    public function test_not_applicable_charge_creates_no_tenant_invoice_line(): void
    {
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'not_applicable',
        ]);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
        ]);

        // Lines count should be 0 because Wifi Fee is not applicable
        $this->assertCount(0, $invoice->lines);

        // Should create an audit record in billing_run_charge_decisions
        $this->assertDatabaseHas('billing_run_charge_decisions', [
            'rental_id' => $this->rental1->id,
            'property_utility_id' => $this->utility->id,
            'resolved_state' => 'not_applicable',
        ]);
    }

    public function test_skipped_this_cycle_creates_no_tenant_invoice_line_and_is_visible_in_audit(): void
    {
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'skipped_this_cycle',
            'reason' => 'Holiday skip',
        ]);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
            'billing_run_id' => 'run_123',
        ]);

        $this->assertCount(0, $invoice->lines);

        // Audit decision entry exists
        $this->assertDatabaseHas('billing_run_charge_decisions', [
            'billing_run_id' => 'run_123',
            'rental_id' => $this->rental1->id,
            'resolved_state' => 'skipped_this_cycle',
            'reason' => 'Holiday skip',
        ]);
    }

    public function test_custom_charge_uses_override_amount_and_currency(): void
    {
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'custom',
            'amount_override' => 20500, // 20,500 KHR override
            'currency_override' => 'KHR',
        ]);

        $this->mock(ExchangeRateService::class, function ($mock) {
            $mock->shouldReceive('fetchUsdToKhr')->andReturn([
                'rate' => 4100.0,
                'date' => now()->toDateString(),
                'source' => 'NBC',
            ]);
        });

        $this->settings->update(['exchange_rate_source' => 'NBC']);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
        ]);

        $line = $invoice->lines()->first();
        // Uses KHR and override amount
        $this->assertEquals('KHR', $line->currency);
        $this->assertEquals(20500, $line->amount);

        // Converted values using snapshot exchange rate 4100.0
        // 20,500 KHR / 4100.0 = 5 USD
        $this->assertEquals(5.00, $line->amount_usd);
        $this->assertEquals(20500, $line->amount_khr);
    }

    public function test_rule_effective_dates_are_respected(): void
    {
        // Rule that is effective only in the future
        ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'free',
            'effective_from' => now()->addMonth()->toDateString(),
            'effective_until' => now()->addMonths(2)->toDateString(),
        ]);

        // Resolving today -> should be normal (default)
        $decisionToday = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
            'date' => now()->toDateString(),
        ]);

        // Resolving next month -> should be free
        $decisionFuture = $this->resolver->resolve([
            'charge_definition_id' => $this->definition->id,
            'rental_id' => $this->rental1->id,
            'date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertEquals('normal', $decisionToday['effective_state']);
        $this->assertEquals('free', $decisionFuture['effective_state']);
    }

    public function test_old_utility_waiver_data_still_resolves_as_waived(): void
    {
        // Create an old legacy utility waiver
        UtilityWaiver::create([
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'rental_id' => $this->rental1->id,
            'waived' => true,
            'created_by_id' => $this->landlord->id,
        ]);

        $decision = $this->resolver->resolve([
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
        ]);

        $this->assertEquals('waived', $decision['effective_state']);
        $this->assertEquals(0.0, $decision['amount']);
        $this->assertEquals('waiver', $decision['source_scope_type']);
    }

    public function test_changing_rule_after_invoice_generation_does_not_change_old_invoice_lines(): void
    {
        // 1. Create a persistent free rule
        $rule = ChargeRule::create([
            'charge_definition_id' => $this->definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'free',
        ]);

        $usage = UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $this->utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'new_reading' => 1,
            'amount_used' => 1,
        ]);

        $invoice = $this->builder->create([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => [$usage],
        ]);

        $line = $invoice->lines()->first();
        $this->assertEquals('free', $line->charge_state);
        $this->assertEquals(0.0, $line->amount);

        // 2. Change rule to normal / custom
        $rule->update([
            'state' => 'custom',
            'amount_override' => 50.00,
            'currency_override' => 'USD',
        ]);

        // 3. Refresh old invoice line, it should remain free/0.0
        $line->refresh();
        $this->assertEquals('free', $line->charge_state);
        $this->assertEquals(0.0, $line->amount);
    }

    /**
     * WHY: todo.md flags "Adjusted" (the tenant-facing label for the `custom`
     * state) as having zero regression coverage. An adjusted charge is the one
     * hidden state that still prints a non-zero figure, so the leak it can spring
     * is the opposite of the others: the tenant must see the *override* and never
     * the un-adjusted price. The original rate stays snapshotted on the line's
     * `unit_price` (landlords need it for the audit trail), which means any
     * regression that recomputes `amount` as unit_price x quantity, or that loses
     * the override between ChargeRuleResolver and InvoiceBuilderService, silently
     * re-bills the tenant the full amount. Here the un-adjusted charge would be
     * 3 kWh x $10.00 = $30.00 against a $3.50 override, so the two can never be
     * confused in the rendered slip.
     */
    public function test_adjusted_charge_shows_only_the_adjusted_amount_in_tenant_output(): void
    {
        [$definition, $utility] = $this->makeMeteredCharge('Electricity', 10.00);

        ChargeRule::create([
            'charge_definition_id' => $definition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'custom',
            'amount_override' => 3.50,
            'currency_override' => 'USD',
        ]);

        $invoice = $this->invoiceFor([
            $this->usageFor($utility, quantity: 3),
        ]);

        $line = $invoice->lines()->sole();

        // Displayed amount is the adjusted one...
        $this->assertEquals('custom', $line->charge_state);
        $this->assertEquals(__('Adjusted'), $line->resolvedChargeStateLabel());
        $this->assertEquals(3.50, $line->amount);
        $this->assertEquals(3.50, $line->amount_usd);
        $this->assertTrue($line->shouldAppearOnTenantInvoice());

        // ...while the underlying, un-adjusted price stays on the line.
        $this->assertEquals(10.00, $line->unit_price);
        $this->assertEquals(3.0, $line->quantity);

        // The invoice total follows the override, not unit_price x quantity.
        $this->assertEquals(3.50, $invoice->fresh()->amount_due);

        $this->tenantView($invoice)
            ->assertSee(__('Adjusted'))
            ->assertSee('$3.50')
            ->assertDontSee('$30.00');
    }

    /**
     * WHY: todo.md flags "Not applicable" as having zero regression coverage.
     * `should_create_line = false` is the only thing keeping this charge off the
     * tenant's invoice, and the suppressed value is deliberately kept alive in
     * billing_run_charge_decisions so landlords can audit what was not billed.
     * That split is the risk: if the audit write ever grows into a real invoice
     * line (or the slip's shouldAppearOnTenantInvoice() filter is dropped), the
     * tenant is billed for a charge their room does not even have. A second,
     * normal charge is billed in the same run so the assertions prove suppression
     * rather than an empty invoice.
     */
    public function test_not_applicable_charge_never_reaches_tenant_facing_invoice_output(): void
    {
        [$hiddenDefinition, $hiddenUtility] = $this->makeMeteredCharge('Cable TV', 10.00);
        [, $billedUtility] = $this->makeMeteredCharge('Trash Fee', 7.00);

        ChargeRule::create([
            'charge_definition_id' => $hiddenDefinition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'not_applicable',
        ]);

        $invoice = $this->invoiceFor([
            $this->usageFor($hiddenUtility, quantity: 1),
            $this->usageFor($billedUtility, quantity: 1),
        ]);

        // Only the normal charge became a line; the invoice total ignores the other.
        $this->assertCount(1, $invoice->lines);
        $this->assertStringContainsString('Trash Fee', $invoice->lines->first()->description);
        $this->assertEquals(7.00, $invoice->fresh()->amount_due);

        // The suppressed value is preserved for the landlord-facing audit trail.
        $decision = BillingRunChargeDecision::where('property_utility_id', $hiddenUtility->id)->sole();
        $this->assertEquals('not_applicable', $decision->resolved_state);
        $this->assertEquals(10.00, $decision->amount);

        // ...and nothing about it reaches the tenant.
        $this->tenantView($invoice)
            ->assertSee('$7.00')
            ->assertDontSee('Cable TV')
            ->assertDontSee(__('Not applicable'))
            ->assertDontSee('$10.00');
    }

    /**
     * WHY: todo.md flags "Skipped this cycle" as having zero regression coverage.
     * It is the most dangerous of the hidden states because it is a *per-run*
     * decision with a landlord-authored reason attached: the reason is internal
     * ("meter unread", "waiting on the provider") and must never surface on a
     * tenant document, and the skip must not carry a zero-amount placeholder line
     * onto the slip either — a $0.00 row for a charge nobody agreed to skip reads
     * as a billing error to the tenant. This asserts both halves: the reason and
     * amount survive in billing_run_charge_decisions, and neither the charge nor
     * its reason renders in the tenant portal.
     */
    public function test_skipped_this_cycle_charge_never_reaches_tenant_facing_invoice_output(): void
    {
        [$hiddenDefinition, $hiddenUtility] = $this->makeMeteredCharge('Water', 10.00);
        [, $billedUtility] = $this->makeMeteredCharge('Trash Fee', 7.00);

        ChargeRule::create([
            'charge_definition_id' => $hiddenDefinition->id,
            'landlord_id' => $this->landlord->id,
            'property_id' => $this->property->id,
            'scope_type' => 'rental',
            'scope_id' => $this->rental1->id,
            'state' => 'skipped_this_cycle',
            'reason' => 'Meter unread this cycle',
        ]);

        $invoice = $this->invoiceFor([
            $this->usageFor($hiddenUtility, quantity: 1),
            $this->usageFor($billedUtility, quantity: 1),
        ], billingRunId: 'run_skip_001');

        $this->assertCount(1, $invoice->lines);
        $this->assertStringContainsString('Trash Fee', $invoice->lines->first()->description);
        $this->assertEquals(7.00, $invoice->fresh()->amount_due);

        // No zero-amount placeholder line was written for the skipped charge.
        $this->assertDatabaseMissing('invoice_lines', [
            'invoice_id' => $invoice->id,
            'charge_state' => 'skipped_this_cycle',
        ]);

        // Reason + suppressed value survive on the landlord-facing audit record.
        $decision = BillingRunChargeDecision::where('property_utility_id', $hiddenUtility->id)->sole();
        $this->assertEquals('run_skip_001', $decision->billing_run_id);
        $this->assertEquals('skipped_this_cycle', $decision->resolved_state);
        $this->assertEquals('Meter unread this cycle', $decision->reason);
        $this->assertEquals(10.00, $decision->amount);

        $this->tenantView($invoice)
            ->assertSee('$7.00')
            ->assertDontSee('Water')
            ->assertDontSee('Meter unread this cycle')
            ->assertDontSee(__('Skipped this cycle'))
            ->assertDontSee('$10.00');
    }

    /**
     * WHY: InvoiceLine::shouldAppearOnTenantInvoice() is the single predicate every
     * tenant-facing surface (portal slip, PDF, thermal print) filters on, and the
     * three tests above can only exercise it for states InvoiceBuilderService
     * actually persists. Legacy rows and hand-edited lines can still carry a hidden
     * state with a real amount on them, so this pins the membership of the hidden
     * set directly: adding a state to the match in resolvedChargeStateLabel()
     * without adding it here is exactly how a hidden charge leaks back onto a
     * tenant document.
     */
    public function test_hidden_charge_states_are_excluded_from_the_tenant_visible_line_filter(): void
    {
        $hidden = ['not_applicable', 'skipped_this_cycle'];
        $visible = ['normal', 'free', 'waived', 'custom'];

        foreach ($hidden as $state) {
            $line = new InvoiceLine(['charge_state' => $state, 'amount' => 25.00]);
            $this->assertFalse(
                $line->shouldAppearOnTenantInvoice(),
                "Charge state [{$state}] must stay off tenant-facing output.",
            );
        }

        foreach ($visible as $state) {
            $line = new InvoiceLine(['charge_state' => $state, 'amount' => 25.00]);
            $this->assertTrue(
                $line->shouldAppearOnTenantInvoice(),
                "Charge state [{$state}] must remain visible to the tenant.",
            );
        }

        // A pre-charge-rule line with no snapshot still resolves through is_waived.
        $legacy = new InvoiceLine(['is_waived' => true, 'amount' => 0.0]);
        $this->assertEquals('waived', $legacy->resolvedChargeState());
        $this->assertTrue($legacy->shouldAppearOnTenantInvoice());
    }

    /**
     * A metered charge definition + its property utility, so each test can bill a
     * charge whose un-adjusted total (rate x quantity) is distinguishable from any
     * override or suppression it asserts against.
     *
     * @return array{0: ChargeDefinition, 1: PropertyUtility}
     */
    private function makeMeteredCharge(string $name, float $rate): array
    {
        $definition = ChargeDefinition::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'name' => $name,
            'category' => 'utility',
            'billing_type' => 'metered',
            'default_amount' => $rate,
            'default_currency' => 'USD',
        ]);

        $utility = PropertyUtility::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'charge_definition_id' => $definition->id,
            'name' => $name,
            'billing_type' => BillingType::Metered,
            'rate' => $rate,
            'currency' => 'USD',
            'unit_of_measure' => 'kWh',
        ]);

        return [$definition, $utility];
    }

    private function usageFor(PropertyUtility $utility, float $quantity): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $this->unit1->id,
            'property_utility_id' => $utility->id,
            'rental_id' => $this->rental1->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_date' => now()->toDateString(),
            'old_reading' => 0,
            'new_reading' => $quantity,
            'amount_used' => $quantity,
        ]);
    }

    /** @param  array<int, UtilityUsage>  $usages */
    private function invoiceFor(array $usages, ?string $billingRunId = null): Invoice
    {
        return $this->builder->create(array_filter([
            'rental' => $this->rental1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->subDay()->toDateString(),
            'include_rent' => false,
            'usages' => $usages,
            'billing_run_id' => $billingRunId,
        ], fn ($value) => $value !== null));
    }

    /** The real tenant-facing surface: the portal invoice slip, rendered over HTTP. */
    private function tenantView(Invoice $invoice): TestResponse
    {
        return $this->actingAs($this->tenant)
            ->get(route('portal.invoice', $invoice))
            ->assertSuccessful();
    }
}
