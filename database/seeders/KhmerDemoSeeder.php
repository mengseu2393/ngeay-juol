<?php

namespace Database\Seeders;

use App\Enums\BillingType;
use App\Enums\ChatMessageType;
use App\Enums\ChatRoomType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\InvoiceStatus;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\PropertyType;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Models\ChargeDefinition;
use App\Models\ChargeRule;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Invoice;
use App\Models\LandlordProfile;
use App\Models\MaintenanceMessage;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TenantProfile;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;
use App\Services\InvoiceBuilderService;
use App\Services\RoomAccountService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Full Cambodian/Khmer demo fixture described in docs/SEED_SPEC.md.
 *
 * DatabaseSeeder calls this only when SEED_DEMO=true. Every lookup uses a stable
 * natural key or marker so the fixture can safely be run repeatedly.
 */
class KhmerDemoSeeder extends Seeder
{
    private const RATE = 4100.0;

    private array $properties = [];

    private array $units = [];

    private array $activeRentals = [];

    private array $usages = [];

    public function run(): void
    {
        if (! Role::where('name', 'super_admin')->exists()) {
            $this->call(RolesAndPermissionsSeeder::class);
        }

        DB::transaction(function (): void {
            $admin = $this->user('demo-super-admin@rentwise.test', 'Sok Dara — Super Admin', 'demo-super-admin', 'super_admin', UserStatus::Active);
            $support = $this->user('demo-support@rentwise.test', 'Chan Sophea — Platform Support', 'demo-support', 'support', UserStatus::Active);

            $full = $this->landlord('demo-full-landlord@rentwise.test', 'Vichea Kim', 'demo-full-landlord', false, 'Dara Rentals ដារ៉ាផ្ទះជួល', 'ABA');
            $simple = $this->landlord('demo-simple-landlord@rentwise.test', 'Sreyna Chea', 'demo-simple-landlord', true, 'Sreyna Simple Homes ផ្ទះសាមញ្ញ', 'ACLEDA');
            $manager = $this->user('demo-manager@rentwise.test', 'Rithy Pich — Property Manager', 'demo-manager', 'landlord_manager', UserStatus::Active, $full);
            $manager->forceFill(['manages_landlord_id' => $full->id])->saveQuietly();

            $lifecycleLandlords = [
                $this->landlord('demo-pending-landlord@rentwise.test', 'Bopha Nuon', 'demo-pending-landlord', false, 'Bopha Homes បុប្ផាផ្ទះជួល', 'Wing'),
                $this->landlord('demo-cancelled-landlord@rentwise.test', 'Veasna Hong', 'demo-cancelled-landlord', false, 'Veasna Properties វាសនាអចលនទ្រព្យ', 'ABA'),
                $this->landlord('demo-suspended-landlord@rentwise.test', 'Kanha Sok', 'demo-suspended-landlord', false, 'Kanha Living កញ្ហាលីវីង', 'ACLEDA'),
            ];

            $plans = $this->seedPlans();
            $this->seedSubscriptions([$full, $simple, ...$lifecycleLandlords], $plans, $admin);

            $this->seedPortfolio($full, $manager);
            $this->seedSimplePortfolio($simple);
            $this->seedUtilitiesAndCharges($full, $admin);
            $this->seedInvoices($full, $admin);
            $this->seedMaintenance($full, $admin);
            $this->seedChat($full, $support);

            // Keep one inactive and one suspended human account visible in the fixture.
            $pastTenant = User::where('email', 'like', 'demo-tenant-%@rentwise.test')->orderBy('id')->first();
            if ($pastTenant) {
                $pastTenant->forceFill(['status' => UserStatus::Inactive])->saveQuietly();
            }
            $suspended = User::firstOrCreate(
                ['email' => 'demo-suspended-tenant@rentwise.test'],
                ['name' => 'Maly Heng — Suspended Tenant', 'username' => 'demo-suspended-tenant', 'password' => 'password'],
            );
            $suspended->forceFill([
                'status' => UserStatus::Suspended,
                'gender' => 'Female',
                'dob' => '1994-08-18',
                'nationality' => 'Khmer',
                'phone_number' => '070 555 018',
                'province' => 'Kandal',
                'district' => 'Ta Khmau',
                'commune' => 'Ta Khmau',
                'village' => 'Phum 3',
            ])->saveQuietly();
            $suspended->syncRoles(['tenant']);
            TenantProfile::updateOrCreate(['user_id' => $suspended->id], [
                'id_card_number' => 'KH-SUSP-0001',
                'occupation' => 'Shop owner',
                'workplace' => 'Ta Khmau Market ផ្សារតាខ្មៅ',
                'monthly_income' => 650,
                'emergency_contact_name' => 'Sovann Mao',
                'emergency_contact_phone' => '012 555 019',
                'emergency_contact_relationship' => 'Brother',
                'guarantor_name' => 'Channary Yim',
                'guarantor_phone' => '092 555 020',
                'guarantor_id_number' => 'KH-G-0001',
                'guarantor_address' => 'Kandal, Ta Khmau, Cambodia',
                'move_in_date' => Carbon::today()->subMonths(2),
                'notes' => 'គណនីសាកល្បងសម្រាប់ស្ថានភាព Suspended។',
            ]);
        });
    }

    private function user(string $email, string $name, string $username, string $role, UserStatus $status, ?User $creator = null): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'username' => $username, 'password' => 'password'],
        );

        $user->fill([
            'name' => $name,
            'username' => $username,
            'phone_number' => '012 555 '.str_pad((string) ($user->id ?: 1), 3, '0', STR_PAD_LEFT),
            'gender' => str_contains($name, 'Sreyna') || str_contains($name, 'Bopha') || str_contains($name, 'Kanha') ? 'Female' : 'Male',
            'dob' => '1988-04-12',
            'nationality' => 'Khmer',
            'province' => 'Phnom Penh',
            'district' => 'Chamkarmon',
            'commune' => 'Tonle Bassac',
            'village' => 'Phum 5',
        ]);
        $user->forceFill([
            'status' => $status,
            'created_by_id' => $creator?->id,
        ])->saveQuietly();
        $user->syncRoles([$role]);

        return $user->refresh();
    }

    private function landlord(string $email, string $name, string $username, bool $simple, string $company, string $bank): User
    {
        $landlord = $this->user($email, $name, $username, 'landlord', UserStatus::Active);
        $landlord->forceFill(['prefers_simple_landlord_mode' => $simple])->saveQuietly();

        LandlordProfile::updateOrCreate(['user_id' => $landlord->id], [
            'company_name' => $company,
            'bank_name' => $bank,
            'bank_account_name' => $company,
            'bank_account_number' => '000'.str_pad((string) $landlord->id, 9, '7', STR_PAD_LEFT),
            'payout_details' => [
                'frequency' => 'monthly',
                'preferred_currency' => $simple ? 'KHR' : 'USD',
                'note' => 'ការទូទាត់ទៅគណនីម្ចាស់ផ្ទះ។',
            ],
            'can_create_tenants' => true,
        ]);

        return $landlord;
    }

    private function seedPlans(): array
    {
        $definitions = [
            ['name' => 'Free Trial', 'slug' => 'demo-free-trial', 'billing_model' => PlanBillingModel::Flat, 'interval' => PlanInterval::Monthly, 'price' => 0, 'unit_price' => null, 'max_units' => 20, 'max_properties' => 1, 'trial_days' => 14, 'grace_days' => 3, 'is_active' => true],
            ['name' => 'Starter (Flat)', 'slug' => 'demo-starter-flat', 'billing_model' => PlanBillingModel::Flat, 'interval' => PlanInterval::Monthly, 'price' => 9, 'unit_price' => null, 'max_units' => 20, 'max_properties' => 2, 'trial_days' => 0, 'grace_days' => 7, 'is_active' => true],
            ['name' => 'Growth (Per-Unit)', 'slug' => 'demo-growth-per-unit', 'billing_model' => PlanBillingModel::PerUnit, 'interval' => PlanInterval::Monthly, 'price' => 0, 'unit_price' => 0.50, 'max_units' => 100, 'max_properties' => 10, 'trial_days' => 0, 'grace_days' => 7, 'is_active' => true],
            ['name' => 'Pro (Tiered)', 'slug' => 'demo-pro-tiered', 'billing_model' => PlanBillingModel::Tiered, 'interval' => PlanInterval::Yearly, 'price' => 199, 'unit_price' => null, 'max_units' => 1000, 'max_properties' => 100, 'trial_days' => 0, 'grace_days' => 14, 'is_active' => true],
            ['name' => 'Quarterly plan', 'slug' => 'demo-quarterly', 'billing_model' => PlanBillingModel::Flat, 'interval' => PlanInterval::Quarterly, 'price' => 25, 'unit_price' => null, 'max_units' => 50, 'max_properties' => 5, 'trial_days' => 0, 'grace_days' => 7, 'is_active' => true],
            ['name' => 'Legacy inactive', 'slug' => 'demo-legacy-inactive', 'billing_model' => PlanBillingModel::Flat, 'interval' => PlanInterval::Monthly, 'price' => 5, 'unit_price' => null, 'max_units' => 10, 'max_properties' => 1, 'trial_days' => 0, 'grace_days' => 3, 'is_active' => false],
        ];

        $plans = [];
        foreach ($definitions as $i => $definition) {
            $plans[$definition['slug']] = SubscriptionPlan::updateOrCreate(
                ['slug' => $definition['slug']],
                [...$definition, 'description' => 'គ្រប់គ្រងអចលនទ្រព្យ និងវិក្កយបត្រ — Khmer rental management plan.', 'features' => ['billing' => true, 'maintenance' => true, 'chat' => true, 'pdf_export' => true], 'currency' => 'USD', 'sort_order' => $i + 1],
            );
        }

        return $plans;
    }

    private function seedSubscriptions(array $landlords, array $plans, User $admin): void
    {
        $specs = [
            ['demo-full-landlord@rentwise.test', 'demo-pro-tiered', SubscriptionStatus::Active, -1, 365],
            ['demo-simple-landlord@rentwise.test', 'demo-free-trial', SubscriptionStatus::Trial, 0, 365],
            ['demo-pending-landlord@rentwise.test', 'demo-growth-per-unit', SubscriptionStatus::Pending, -30, -1],
            ['demo-cancelled-landlord@rentwise.test', 'demo-starter-flat', SubscriptionStatus::Cancelled, -180, -30],
            ['demo-suspended-landlord@rentwise.test', 'demo-quarterly', SubscriptionStatus::Suspended, -10, 90],
        ];

        $byEmail = collect($landlords)->keyBy('email');
        $subscriptions = [];
        foreach ($specs as [$email, $planSlug, $status, $startOffset, $endOffset]) {
            $landlord = $byEmail[$email];
            $plan = $plans[$planSlug];
            $starts = Carbon::today()->addDays($startOffset);
            $ends = Carbon::today()->addDays($endOffset);

            $subscription = Subscription::withoutGlobalScopes()->updateOrCreate(
                ['landlord_id' => $landlord->id],
                [
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'billing_model' => $plan->billing_model,
                    'interval' => $plan->interval,
                    'price' => $plan->price,
                    'unit_price' => $plan->unit_price,
                    'max_units' => $plan->max_units,
                    'max_properties' => $plan->max_properties,
                    'features' => $plan->features,
                    'currency' => 'USD',
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'grace_ends_at' => $ends->copy()->addDays($plan->grace_days ?: 7),
                    'trial_ends_at' => $status === SubscriptionStatus::Trial ? Carbon::today()->addDays(14) : null,
                    'auto_renew' => ! in_array($status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Suspended], true),
                    'cancelled_at' => $status === SubscriptionStatus::Cancelled ? now()->subDays(29) : null,
                    'cancellation_reason' => $status === SubscriptionStatus::Cancelled ? 'Demo lifecycle: landlord requested cancellation.' : null,
                    'suspended_at' => $status === SubscriptionStatus::Suspended ? now()->subDays(2) : null,
                    'suspension_reason' => $status === SubscriptionStatus::Suspended ? 'Demo lifecycle: payment review pending.' : null,
                ],
            );
            $subscriptions[] = $subscription;

            foreach (SubscriptionAction::cases() as $action) {
                $marker = 'Demo lifecycle: '.$action->name;
                if (! SubscriptionHistory::withoutGlobalScopes()->where('subscription_id', $subscription->id)->where('note', $marker)->exists()) {
                    SubscriptionHistory::create([
                        'subscription_id' => $subscription->id,
                        'landlord_id' => $landlord->id,
                        'plan_id' => $plan->id,
                        'action' => $action,
                        'period_start' => $starts,
                        'period_end' => $ends,
                        'price' => $plan->price,
                        'unit_count' => 0,
                        'amount_charged' => $plan->price,
                        'meta' => ['demo' => true, 'action' => $action->name],
                        'note' => $marker,
                        'created_by_id' => $admin->id,
                    ]);
                }
            }
        }

        foreach (SubscriptionPaymentStatus::cases() as $i => $status) {
            $subscription = $subscriptions[$i % count($subscriptions)];
            $gatewayId = 'demo-sub-payment-'.$status->name;
            SubscriptionPayment::withoutGlobalScopes()->updateOrCreate(
                ['gateway' => 'demo', 'gateway_transaction_id' => $gatewayId],
                [
                    'subscription_id' => $subscription->id,
                    'landlord_id' => $subscription->landlord_id,
                    'plan_id' => $subscription->plan_id,
                    'amount' => max(1, (float) $subscription->price),
                    'currency' => 'USD',
                    'method' => [PaymentMethod::Cash, PaymentMethod::BankTransfer, PaymentMethod::Card, PaymentMethod::MobilePayment][$i],
                    'status' => $status,
                    'paid_at' => $status === SubscriptionPaymentStatus::Pending || $status === SubscriptionPaymentStatus::Failed ? null : now()->subDays($i + 1),
                    'covers_from' => Carbon::today()->subMonth(),
                    'covers_to' => Carbon::today()->addMonth(),
                    'gateway' => 'demo',
                    'gateway_transaction_id' => $gatewayId,
                    'gateway_ref' => 'DEMO-SUB-'.($i + 1),
                    'receipt_number' => 'DEMO-SUB-RECEIPT-'.($i + 1),
                    'note' => 'ការទូទាត់សមាជិកភាពសាកល្បង — '.$status->name,
                    'recorded_by_id' => $admin->id,
                ],
            );
        }
    }

    private function seedPortfolio(User $landlord, User $manager): void
    {
        $blueprint = [
            ['name' => 'Riverside Residences', 'type' => PropertyType::Apartment, 'city' => 'Phnom Penh', 'district' => 'Chamkarmon', 'floors' => 2, 'perFloor' => 3, 'rent' => 240, 'currency' => 'USD', 'prefix' => 'RW', 'mode' => FirstMonthBillingMode::FullMonth],
            ['name' => 'Sunrise Garden Villas', 'type' => PropertyType::Villa, 'city' => 'Siem Reap', 'district' => 'Svay Dangkum', 'floors' => 2, 'perFloor' => 2, 'rent' => 450, 'currency' => 'USD', 'prefix' => 'SGV', 'mode' => FirstMonthBillingMode::Prorated],
            ['name' => 'City Center Condos', 'type' => PropertyType::Condo, 'city' => 'Phnom Penh', 'district' => 'Daun Penh', 'floors' => 3, 'perFloor' => 2, 'rent' => 380, 'currency' => 'USD', 'prefix' => 'CCC', 'mode' => FirstMonthBillingMode::HalfMonth],
            ['name' => 'Angkor Family House', 'type' => PropertyType::House, 'city' => 'Siem Reap', 'district' => 'Sala Kamreuk', 'floors' => 1, 'perFloor' => 3, 'rent' => 520, 'currency' => 'USD', 'prefix' => 'AFH', 'mode' => FirstMonthBillingMode::FullMonth],
            ['name' => 'Central Market Shops', 'type' => PropertyType::Commercial, 'city' => 'Phnom Penh', 'district' => 'Daun Penh', 'floors' => 1, 'perFloor' => 4, 'rent' => 900000, 'currency' => 'KHR', 'prefix' => 'CMS', 'mode' => FirstMonthBillingMode::Prorated],
            ['name' => 'Riverside Annex', 'type' => PropertyType::Other, 'city' => 'Kampot', 'district' => 'Kampot', 'floors' => 1, 'perFloor' => 2, 'rent' => 180, 'currency' => 'USD', 'prefix' => 'RSA', 'mode' => FirstMonthBillingMode::HalfMonth],
        ];

        foreach ($blueprint as $bp) {
            $property = $this->property($landlord, $bp['name'], $bp['type'], $bp['city'], $bp['district']);
            $this->setting($property, $bp['prefix'], $bp['currency'], $bp['mode'], $bp['name'] === 'Riverside Residences', false);
            $this->properties[$property->name] = $property;

            $index = 0;
            for ($floor = 1; $floor <= $bp['floors']; $floor++) {
                for ($number = 1; $number <= $bp['perFloor']; $number++) {
                    $index++;
                    $room = strtoupper(substr($bp['prefix'], 0, 1)).'-'.$floor.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                    $special = match ($bp['name'].'-'.$room) {
                        'Riverside Residences-R-103' => UnitStatus::Available,
                        'Sunrise Garden Villas-S-202' => UnitStatus::Maintenance,
                        'City Center Condos-C-302' => UnitStatus::Unavailable,
                        default => UnitStatus::Occupied,
                    };
                    $unit = Unit::withoutGlobalScopes()->updateOrCreate(
                        ['property_id' => $property->id, 'room_number' => $room],
                        [
                            'landlord_id' => $landlord->id,
                            'floor_number' => (string) $floor,
                            'room_type' => $bp['type'] === PropertyType::Commercial ? 'Shop' : ($floor === 1 ? '1-Bedroom' : '2-Bedroom'),
                            'rent_amount' => $bp['rent'],
                            'rent_currency' => $bp['currency'],
                            'due_date' => Carbon::today()->day(7),
                            'status' => $special,
                            'description' => 'បន្ទប់ '.$room.' — Khmer demo unit.',
                        ],
                    );
                    $unit->loadMissing('property');
                    $this->units[$property->name.'-'.$room] = $unit;

                    if ($special !== UnitStatus::Occupied) {
                        continue;
                    }

                    app(RoomAccountService::class)->createForUnit($unit, 'password');
                    $historyCount = $index <= 2 ? 3 : 1;
                    $this->seedTenancyTimeline($unit, $landlord, $historyCount, $index, $manager);
                }
            }
        }
    }

    private function seedSimplePortfolio(User $landlord): void
    {
        $property = $this->property($landlord, 'Kampot Simple Rooms', PropertyType::Other, 'Kampot', 'Kampot');
        $this->setting($property, 'KSR', 'KHR', FirstMonthBillingMode::HalfMonth, false, true);
        $this->properties[$property->name] = $property;

        for ($number = 1; $number <= 3; $number++) {
            $room = 'K-'.$number.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $unit = Unit::withoutGlobalScopes()->updateOrCreate(
                ['property_id' => $property->id, 'room_number' => $room],
                ['landlord_id' => $landlord->id, 'floor_number' => '1', 'room_type' => 'Studio', 'rent_amount' => 650000, 'rent_currency' => 'KHR', 'due_date' => Carbon::today()->day(5), 'status' => UnitStatus::Occupied, 'description' => 'បន្ទប់សាមញ្ញ '.$room],
            );
            $unit->loadMissing('property');
            app(RoomAccountService::class)->createForUnit($unit, 'password');
            $this->seedTenancyTimeline($unit, $landlord, 1, $number + 20, null);
        }
    }

    private function property(User $landlord, string $name, PropertyType $type, string $city, string $district): Property
    {
        $property = Property::withoutGlobalScopes()->updateOrCreate(
            ['landlord_id' => $landlord->id, 'name' => $name],
            [
                'property_type' => $type,
                'description' => $name.' — ផ្ទះជួលគុណភាពល្អ សម្រាប់សាកល្បង RentWise។',
                'address_line' => 'Street 240, '.$district,
                'street' => 'Street 240',
                'village' => 'Phum 5',
                'commune' => $district === 'Chamkarmon' ? 'Tonle Bassac' : $district,
                'district' => $district,
                'city' => $city,
                'postal_code' => '12000',
                'amenities' => ['parking' => true, 'wifi' => true, 'security' => true, 'elevator' => $type === PropertyType::Condo],
            ],
        );

        return $property;
    }

    private function setting(Property $property, string $prefix, string $currency, FirstMonthBillingMode $mode, bool $monthly, bool $createOnMoveIn): PropertySetting
    {
        return PropertySetting::updateOrCreate(['property_id' => $property->id], [
            'currency' => $currency,
            'usd_khr_exchange_rate' => self::RATE,
            'exchange_rate_date' => Carbon::today()->subDay(),
            'exchange_rate_source' => 'manual',
            'exchange_rate_fetched_at' => now()->subDay(),
            'invoice_prefix' => $prefix,
            'due_day_of_month' => 7,
            'invoice_due_days' => 7,
            'late_fee' => $currency === 'KHR' ? 20000 : 5,
            'default_lease_months' => 12,
            'deposit_policy' => 'One month security deposit — ប្រាក់កក់មួយខែ',
            'first_month_billing_mode' => $mode,
            'proration_cutoff_day' => 15,
            'require_first_month_upfront' => $mode !== FirstMonthBillingMode::FullMonth,
            'create_invoice_on_move_in' => $createOnMoveIn,
            'upfront_deposit_months' => $mode === FirstMonthBillingMode::FullMonth ? 1 : 0,
            'monthly_billing_enabled' => $monthly,
            'water_billing_default' => 'Metered water — PPWSA',
            'parking_info' => 'Parking included for tenants. ចំណតរថយន្តមានសុវត្ថិភាព។',
            'insurance_info' => 'Building cover held by landlord — មានធានារ៉ាប់រងអគារ។',
            'caretaker_name' => 'Sothea Nop សុធា នព្វ',
            'caretaker_phone' => '092 777 121',
        ]);
    }

    private function seedTenancyTimeline(Unit $unit, User $landlord, int $count, int $seed, ?User $manager): void
    {
        $service = app(RoomAccountService::class);
        $names = ['Sok Dara', 'Chan Sophea', 'Lyhour Meas', 'Bopha Nuon', 'Pisey Lim', 'Samnang Ouk', 'Theary Sen', 'Dara Kong', 'Sovann Mao', 'Channary Yim', 'Phalla Ros', 'Vannak Tep', 'Visal Chhem', 'Sreypov Eng', 'Chenda Prak', 'Rattana Sam'];
        $base = Carbon::today()->startOfMonth()->subMonths(($count - 1) * 8 + 2);

        for ($i = 0; $i < $count; $i++) {
            $start = $base->copy()->addMonths($i * 8);
            $active = $i === $count - 1;
            $end = $active ? null : $start->copy()->addMonths(6)->endOfMonth();
            $status = $active ? RentalStatus::Active : ($i % 2 === 0 ? RentalStatus::Expired : RentalStatus::Vacated);
            $name = $names[($seed + $i) % count($names)];

            $rental = Rental::withoutGlobalScopes()->where('unit_id', $unit->id)->whereDate('start_date', $start->toDateString())->first();
            if (! $rental) {
                $rental = new Rental([
                    'landlord_id' => $landlord->id,
                    'property_id' => $unit->property_id,
                    'unit_id' => $unit->id,
                    'occupant_name' => $name,
                    'occupant_phone' => '0'.(70 + (($seed + $i) % 20)).' 555 '.str_pad((string) (100 + $unit->id + $i), 3, '0', STR_PAD_LEFT),
                    'occupant_id_card' => 'KH-'.str_pad((string) ($unit->id * 100 + $i), 8, '0', STR_PAD_LEFT),
                    'occupant_address' => 'Phnom Penh, Cambodia — កម្ពុជា',
                    'occupant_gender' => $i % 2 ? 'Female' : 'Male',
                    'occupant_dob' => '1992-06-15',
                    'occupant_nationality' => 'Khmer',
                    'occupant_workplace' => $i % 2 ? 'Siem Reap Garment Workshop' : 'ABA Bank Phnom Penh',
                    'emergency_contact_name' => 'Rattana Sam',
                    'emergency_contact_phone' => '012 444 888',
                    'emergency_contact_relationship' => 'Relative',
                    'guarantor_name' => 'Bunthoeun Chhay',
                    'guarantor_phone' => '092 333 777',
                    'guarantor_id_number' => 'KH-G-'.str_pad((string) ($unit->id + $i), 6, '0', STR_PAD_LEFT),
                    'guarantor_address' => 'Kandal, Ta Khmau',
                    'monthly_rent' => $unit->rent_amount,
                    'monthly_rent_currency' => $unit->rent_currency,
                    'security_deposit' => $unit->rent_amount,
                    'security_deposit_currency' => $unit->rent_currency,
                    'lease_agreement' => 'demo/lease-'.$unit->id.'-'.$i.'.pdf',
                    'terms_conditions' => 'Rent due on the 7th. លក្ខខណ្ឌជួលត្រូវបានព្រមព្រៀង។',
                    'notes' => 'អ្នកជួលសាកល្បងលេខ '.($i + 1).' សម្រាប់បង្ហាញប្រវត្តិ។',
                    'signed_at' => $start->copy()->subDays(3),
                    'status' => $status,
                    'start_date' => $start,
                    'end_date' => $end,
                    'next_invoice_date' => $active && $unit->property?->settings?->monthly_billing_enabled ? Carbon::today()->startOfMonth()->addMonth() : null,
                ]);
                $rental->setRelation('unit', $unit);
                $service->createForRental($rental, 'password');
            }

            $tenant = $rental->tenant()->withoutGlobalScopes()->first();
            if ($tenant) {
                $this->tenantProfile($tenant, $seed + $i, $active ? UserStatus::Active : UserStatus::Inactive);
            }
            if ($i === 0 && count($this->units) === 1 && $rental->getMedia('id_cards')->isEmpty()) {
                try {
                    $rental->addMediaFromString('RentWise demo lease identity document')->usingFileName('demo-lease-'.$unit->id.'.pdf')->toMediaCollection('id_cards');
                } catch (\Throwable) {
                    // Media is optional in installations where the library is disabled.
                }
            }
            if ($active) {
                $this->activeRentals[$unit->id] = $rental->refresh();
            }
        }
    }

    private function tenantProfile(User $tenant, int $seed, UserStatus $status): void
    {
        $tenant->fill([
            'phone_number' => '012 '.str_pad((string) (300 + $seed), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (700 + $seed), 3, '0', STR_PAD_LEFT),
            'gender' => $seed % 2 ? 'Female' : 'Male',
            'dob' => Carbon::create(1988 + ($seed % 12), 1 + ($seed % 11), 5 + ($seed % 20)),
            'nationality' => 'Khmer',
            'province' => $seed % 3 ? 'Phnom Penh' : 'Siem Reap',
            'district' => $seed % 3 ? 'Chamkarmon' : 'Svay Dangkum',
            'commune' => $seed % 3 ? 'Tonle Bassac' : 'Svay Dangkum',
            'village' => 'Phum '.(($seed % 5) + 1),
        ]);
        $tenant->forceFill(['status' => $status])->saveQuietly();
        $tenant->syncRoles(['tenant']);
        TenantProfile::updateOrCreate(['user_id' => $tenant->id], [
            'id_card_number' => 'KH-T-'.str_pad((string) (10000 + $seed), 6, '0', STR_PAD_LEFT),
            'occupation' => ['Teacher', 'Tuk-tuk driver', 'Garment worker', 'Shop owner', 'Bank staff', 'Student'][$seed % 6],
            'workplace' => ['Sala Primary School', 'Phnom Penh Tuk-tuk Association', 'Sihanoukville Garment Factory', 'Central Market', 'ABA Bank', 'Royal University'][($seed + 1) % 6],
            'monthly_income' => 250 + (($seed % 8) * 100),
            'emergency_contact_name' => 'Maly Heng',
            'emergency_contact_phone' => '070 222 '.str_pad((string) (100 + $seed), 3, '0', STR_PAD_LEFT),
            'emergency_contact_relationship' => 'Family',
            'guarantor_name' => 'Sothea Nop',
            'guarantor_phone' => '092 111 '.str_pad((string) (100 + $seed), 3, '0', STR_PAD_LEFT),
            'guarantor_id_number' => 'KH-GU-'.str_pad((string) $seed, 6, '0', STR_PAD_LEFT),
            'guarantor_address' => 'Phnom Penh, Cambodia',
            'move_in_date' => Carbon::today()->subMonths(2),
            'notes' => 'ប្រវត្តិអ្នកជួល និងព័ត៌មានបន្ទាន់សម្រាប់សាកល្បង។',
        ]);
    }

    private function seedUtilitiesAndCharges(User $landlord, User $admin): void
    {
        foreach ($this->properties as $property) {
            $currency = $property->settings?->currency ?: 'USD';
            $electricity = $this->utility($property, $landlord, 'Electricity', 'kWh', BillingType::Metered, $currency === 'KHR' ? 1000 : 0.25, 'EDC', true);
            $water = $this->utility($property, $landlord, 'Water', 'm³', $property->id % 2 ? BillingType::Metered : BillingType::Flat, $currency === 'KHR' ? 1200 : 0.30, 'PPWSA', true);
            $this->utility($property, $landlord, 'Trash / Cleaning', 'month', BillingType::Flat, $currency === 'KHR' ? 10000 : 2.50, 'RentWise Services', true);
            $shared = $this->utility($property, $landlord, 'Shared master-meter', 'kWh', BillingType::Shared, $currency === 'KHR' ? 900 : 0.20, 'Building Master Meter', $property->id % 3 !== 0);

            if ($property->name === 'Riverside Residences') {
                $definitions = [
                    ['name' => 'Parking', 'category' => 'parking', 'billing_type' => 'flat', 'amount' => 15, 'currency' => 'USD', 'unit' => 'space'],
                    ['name' => 'Internet / Wifi', 'category' => 'internet', 'billing_type' => 'flat', 'amount' => 8, 'currency' => 'USD', 'unit' => 'month'],
                    ['name' => 'Service Fee', 'category' => 'service', 'billing_type' => 'flat', 'amount' => 5, 'currency' => 'USD', 'unit' => 'month'],
                    ['name' => 'Electricity charge', 'category' => 'utility', 'billing_type' => 'metered', 'amount' => 0.25, 'currency' => 'USD', 'unit' => 'kWh'],
                ];
                $defs = [];
                foreach ($definitions as $definition) {
                    $defs[$definition['name']] = ChargeDefinition::withoutGlobalScopes()->updateOrCreate(
                        ['property_id' => $property->id, 'name' => $definition['name']],
                        ['landlord_id' => $landlord->id, 'category' => $definition['category'], 'billing_type' => $definition['billing_type'], 'default_amount' => $definition['amount'], 'default_currency' => $definition['currency'], 'unit_of_measure' => $definition['unit'], 'is_active' => true, 'notes' => 'ថ្លៃសេវាសាកល្បង — '.$definition['name']],
                    );
                }
                $electricity->update(['charge_definition_id' => $defs['Electricity charge']->id]);

                $active = $this->activeRentals[array_key_first($this->activeRentals)] ?? null;
                $unit = $active?->unit;
                $rules = [
                    ['name' => 'amount override', 'scope_type' => 'property', 'scope_id' => $property->id, 'amount_override' => 12, 'currency_override' => null, 'state' => 'custom', 'from' => Carbon::today()->subMonth(), 'until' => Carbon::today()->addMonth(), 'reason' => 'Current property parking promotion'],
                    ['name' => 'currency override', 'scope_type' => 'property', 'scope_id' => $property->id, 'amount_override' => null, 'currency_override' => 'KHR', 'state' => 'custom', 'from' => null, 'until' => null, 'reason' => 'KHR billing exception'],
                    ['name' => 'unit scoped rule', 'scope_type' => 'unit', 'scope_id' => $unit?->id ?: $property->id, 'amount_override' => 3, 'currency_override' => 'USD', 'state' => 'custom', 'from' => null, 'until' => null, 'reason' => 'Specific room discount'],
                    ['name' => 'future rule', 'scope_type' => 'property', 'scope_id' => $property->id, 'amount_override' => 20, 'currency_override' => 'USD', 'state' => 'custom', 'from' => Carbon::today()->addMonth(), 'until' => Carbon::today()->addMonths(2), 'reason' => 'Future rate window'],
                    ['name' => 'expired rule', 'scope_type' => 'property', 'scope_id' => $property->id, 'amount_override' => 10, 'currency_override' => 'USD', 'state' => 'custom', 'from' => Carbon::today()->subMonths(3), 'until' => Carbon::today()->subMonth(), 'reason' => 'Expired introductory rate'],
                ];
                foreach ($rules as $rule) {
                    ChargeRule::withoutGlobalScopes()->updateOrCreate(
                        ['property_id' => $property->id, 'scope_type' => $rule['scope_type'], 'scope_id' => $rule['scope_id'], 'reason' => $rule['reason']],
                        ['charge_definition_id' => $defs['Parking']->id, 'property_utility_id' => $rule['name'] === 'currency override' ? $electricity->id : null, 'landlord_id' => $landlord->id, 'state' => $rule['state'], 'amount_override' => $rule['amount_override'], 'currency_override' => $rule['currency_override'], 'effective_from' => $rule['from'], 'effective_until' => $rule['until'], 'created_by_id' => $admin->id],
                    );
                }
            }

            $activeRentals = Rental::withoutGlobalScopes()->where('property_id', $property->id)->where('status', RentalStatus::Active->value)->get();
            foreach ($activeRentals->take(2) as $rental) {
                foreach ([$electricity, $water] as $utility) {
                    if ($utility->billing_type === BillingType::Flat) {
                        continue;
                    }
                    for ($month = 2; $month >= 0; $month--) {
                        $date = Carbon::today()->startOfMonth()->subMonths($month);
                        $isFirstProperty = $property->name === array_key_first($this->properties);
                        $type = $isFirstProperty && $month === 2 ? ReadingType::Estimated : ($isFirstProperty && $month === 1 ? ReadingType::Fixed : ReadingType::Actual);
                        $old = 1000 + ($rental->unit_id * 10) + ($month * 100);
                        $used = 80 + (($rental->unit_id + $month) % 50);
                        $usage = UtilityUsage::withoutGlobalScopes()->updateOrCreate(
                            ['property_utility_id' => $utility->id, 'unit_id' => $rental->unit_id, 'rental_id' => $rental->id, 'reading_date' => $date],
                            ['landlord_id' => $landlord->id, 'recorded_by_id' => $admin->id, 'reading_type' => $type, 'old_reading' => $type === ReadingType::Fixed ? null : $old, 'new_reading' => $type === ReadingType::Fixed ? null : $old + $used, 'amount_used' => $type === ReadingType::Fixed ? 25 : $used, 'is_waived' => false],
                        );
                        $this->usages[$rental->id][] = $usage;
                    }
                }
            }

            // Keep the primary paid invoice's utility line billable; waive a
            // different room so the waiver path is still visible in the data.
            $waivedRental = $activeRentals->get(1) ?: $activeRentals->first();
            if ($waivedRental) {
                UtilityWaiver::withoutGlobalScopes()->updateOrCreate(
                    ['property_utility_id' => $electricity->id, 'rental_id' => $waivedRental->id],
                    ['landlord_id' => $landlord->id, 'property_id' => null, 'unit_id' => null, 'waived' => true, 'created_by_id' => $admin->id],
                );
            }
        }
    }

    private function utility(Property $property, User $landlord, string $name, string $unit, BillingType $billingType, float $rate, string $provider, bool $active): PropertyUtility
    {
        return PropertyUtility::withoutGlobalScopes()->updateOrCreate(
            ['property_id' => $property->id, 'name' => $name],
            ['landlord_id' => $landlord->id, 'unit_of_measure' => $unit, 'billing_type' => $billingType, 'rate' => $rate, 'currency' => $property->settings?->currency ?: 'USD', 'provider' => $provider, 'account_ref' => 'KH-'.$property->id.'-'.strtoupper(substr($name, 0, 3)), 'is_active' => $active, 'notes' => 'ទិន្នន័យឧបករណ៍សម្រាប់ '.$name],
        );
    }

    private function seedInvoices(User $landlord, User $admin): void
    {
        $rentals = collect($this->activeRentals)->values();
        if ($rentals->count() < 6) {
            return;
        }
        $builder = app(InvoiceBuilderService::class);
        $paid = $this->demoInvoice($builder, $rentals[0], 'paid-main', Carbon::today()->subMonth()->startOfMonth(), Carbon::today()->subMonth()->endOfMonth(), InvoiceStatus::Pending, [$this->usages[$rentals[0]->id][0] ?? null, $this->usages[$rentals[0]->id][1] ?? null], [['description' => 'Repair contribution ជួសជុល', 'amount' => 18, 'currency' => 'USD']], 'វិក្កយបត្របង់រួច — ទឹកប្រាក់ និងកំណត់ចំណាំជាភាសាខ្មែរ។');
        $this->payment($paid, $admin, PaymentMethod::Cash, 'USD', (float) $paid->total_usd, 'demo-payment-cash');

        $partial = $this->demoInvoice($builder, $rentals[1], 'partial', Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth(), InvoiceStatus::Pending, [], [], 'វិក្កយបត្របង់មួយផ្នែក។');
        $this->payment($partial, $admin, PaymentMethod::BankTransfer, 'USD', max(1, round((float) $partial->total_usd / 2, 2)), 'demo-payment-bank');

        $this->demoInvoice($builder, $rentals[2], 'pending', Carbon::today()->addMonth()->startOfMonth(), Carbon::today()->addMonth()->endOfMonth(), InvoiceStatus::Pending, [], [], 'វិក្កយបត្ររង់ចាំបង់។');
        $this->demoInvoice($builder, $rentals[3], 'overdue', Carbon::today()->subMonths(2)->startOfMonth(), Carbon::today()->subMonths(2)->endOfMonth(), InvoiceStatus::Pending, [], [], 'វិក្កយបត្រហួសកាលកំណត់។', Carbon::today()->subMonth());
        $this->demoInvoice($builder, $rentals[4], 'cancelled', Carbon::today()->subMonth()->startOfMonth(), Carbon::today()->subMonth()->endOfMonth(), InvoiceStatus::Cancelled, [], [], 'វិក្កយបត្របានលុបចោល។');
        $this->demoInvoice($builder, $rentals[5], 'draft', Carbon::today()->addMonth()->startOfMonth(), Carbon::today()->addMonth()->endOfMonth(), InvoiceStatus::Draft, [], [], 'សេចក្ដីព្រាងវិក្កយបត្រ។');

        foreach ([PaymentMethod::Card, PaymentMethod::MobilePayment, PaymentMethod::Cheque, PaymentMethod::Other] as $i => $method) {
            $invoice = $this->demoInvoice($builder, $rentals[$i + 2], 'method-'.$method->name, Carbon::today()->subMonths(3 + $i)->startOfMonth(), Carbon::today()->subMonths(3 + $i)->endOfMonth(), InvoiceStatus::Pending, [], [['description' => 'Ad-hoc demo fee ថ្លៃសេវា', 'amount' => 4 + $i, 'currency' => 'USD']], 'បង់ដោយវិធីសាស្ត្រផ្សេងៗ។');
            $this->payment($invoice, $admin, $method, 'USD', (float) $invoice->total_usd, 'demo-payment-'.$method->name);
        }

        // A KHR invoice paid in USD exercises cross-currency ledger conversion.
        $khrRental = $rentals->first(fn (Rental $r) => $r->monthly_rent_currency === 'KHR');
        if ($khrRental) {
            $invoice = $this->demoInvoice($builder, $khrRental, 'cross-currency', Carbon::today()->subMonth()->startOfMonth(), Carbon::today()->subMonth()->endOfMonth(), InvoiceStatus::Pending, [], [], 'បង់ជាដុល្លារសម្រាប់វិក្កយបត្រ ៛។');
            $this->payment($invoice, $admin, PaymentMethod::MobilePayment, 'USD', max(1, round((float) $invoice->total_usd, 2)), 'demo-payment-cross-currency');
        }

        // Give every property's active rentals a visible monthly history. The
        // periods are deliberately recent so the landlord invoice screens have
        // useful data without creating an unbounded fixture on every run.
        $this->seedInvoiceHistory($builder);
    }

    private function seedInvoiceHistory(InvoiceBuilderService $builder): void
    {
        foreach ($this->activeRentals as $rental) {
            for ($offset = 3; $offset >= 0; $offset--) {
                $start = Carbon::today()->subMonths($offset)->startOfMonth();
                $end = $start->copy()->endOfMonth();

                // Do not create a second invoice for a rental and billing period
                // when one of the coverage invoices above already owns that period.
                if (Invoice::withoutGlobalScopes()
                    ->where('rental_id', $rental->id)
                    ->whereDate('period_start', $start->toDateString())
                    ->whereDate('period_end', $end->toDateString())
                    ->exists()) {
                    continue;
                }

                $usages = UtilityUsage::withoutGlobalScopes()
                    ->where('rental_id', $rental->id)
                    ->whereBetween('reading_date', [$start->toDateString(), $end->toDateString()])
                    ->whereDoesntHave('invoiceLine')
                    ->get()
                    ->all();
                $currency = $rental->monthly_rent_currency ?: 'USD';
                $adhoc = $offset % 2 === 0
                    ? [['description' => 'Monthly service fee ថ្លៃសេវាប្រចាំខែ', 'amount' => $currency === 'KHR' ? 10000 : 4.50, 'currency' => $currency]]
                    : [];

                $this->demoInvoice(
                    $builder,
                    $rental,
                    'history-'.$rental->id.'-'.$start->format('Ym'),
                    $start,
                    $end,
                    InvoiceStatus::Pending,
                    $usages,
                    $adhoc,
                    'ប្រវត្តិវិក្កយបត្រប្រចាំខែ — Monthly demo invoice history.',
                );
            }
        }
    }

    private function demoInvoice(InvoiceBuilderService $builder, Rental $rental, string $key, Carbon $start, Carbon $end, InvoiceStatus $status, array $usages, array $adhoc, string $notes, ?Carbon $dueDate = null): Invoice
    {
        $marker = '[DEMO-'.$key.']';
        $invoice = Invoice::withoutGlobalScopes()->where('rental_id', $rental->id)->where('notes', 'like', '%'.$marker.'%')->first();
        if ($invoice) {
            return $invoice->refresh();
        }
        $usages = array_values(array_filter($usages));

        return $builder->create([
            'rental' => $rental,
            'period_start' => $start,
            'period_end' => $end,
            'issue_date' => Carbon::today(),
            'due_date' => $dueDate ?: ($status === InvoiceStatus::Overdue ? Carbon::today()->subDay() : Carbon::today()->addDays(7)),
            'status' => $status,
            'usages' => $usages,
            'adhoc' => $adhoc,
            'notes' => $marker.' '.$notes,
        ]);
    }

    private function payment(Invoice $invoice, User $admin, PaymentMethod $method, string $currency, float $amount, string $reference): Payment
    {
        $existing = Payment::where('transaction_ref', $reference)->first();
        if ($existing) {
            return $existing;
        }

        return $invoice->recordPayment([
            'recorded_by_id' => $admin->id,
            'amount' => max(0.01, $amount),
            'currency' => $currency,
            'method' => $method,
            'paid_at' => now()->subDay(),
            'transaction_ref' => $reference,
            'receipt_number' => 'DEMO-RECEIPT-'.strtoupper(substr($reference, -12)),
            'note' => 'ទទួលប្រាក់សាកល្បង — '.$method->name,
        ]);
    }

    private function seedMaintenance(User $landlord, User $admin): void
    {
        $rentals = collect($this->activeRentals)->values()->take(5);
        foreach ($rentals as $i => $rental) {
            $request = MaintenanceRequest::withoutGlobalScopes()->updateOrCreate(
                ['landlord_id' => $landlord->id, 'unit_id' => $rental->unit_id, 'title' => ['ម៉ាស៊ីនត្រជាក់ខូច — Air conditioner not cooling', 'ទឹកលេច — Water leak in bathroom', 'ភ្លើងខូច — Broken light', 'ទ្វារខូច — Door repair needed', 'សំរាម — Waste collection issue'][$i]],
                ['tenant_id' => $rental->tenant_id, 'property_id' => $rental->property_id, 'rental_id' => $rental->id, 'description' => 'សូមជួយជួសជុលឱ្យបានឆាប់។ Tenant report for the demo maintenance thread.', 'priority' => MaintenancePriority::cases()[$i % count(MaintenancePriority::cases())], 'status' => MaintenanceStatus::cases()[$i % count(MaintenanceStatus::cases())]],
            );
            MaintenanceMessage::firstOrCreate(['request_id' => $request->id, 'sender_id' => $rental->tenant_id, 'body' => 'សួស្តីម្ចាស់ផ្ទះ ខ្ញុំសូមរាយការណ៍បញ្ហានេះ។ Hello, I am reporting this issue.']);
            MaintenanceMessage::firstOrCreate(['request_id' => $request->id, 'sender_id' => $landlord->id, 'body' => 'បានទទួល — ក្រុមជួសជុលនឹងមកពិនិត្យ។ Received — our technician will inspect it.']);
            MaintenanceMessage::firstOrCreate(['request_id' => $request->id, 'sender_id' => $admin->id, 'body' => 'ស្ថានភាពត្រូវបានធ្វើបច្ចុប្បន្នភាពសម្រាប់ audit trail. Status-change note for the demo.']);

            if ($i === 0 && $request->getMedia('photos')->isEmpty()) {
                try {
                    $request->addMediaFromString('RentWise demo maintenance photo')->usingFileName('demo-maintenance-'.$request->id.'.jpg')->toMediaCollection('photos');
                } catch (\Throwable) {
                    // Media is optional in installations where the library is disabled.
                }
            }
        }
    }

    private function seedChat(User $landlord, User $support): void
    {
        $rental = collect($this->activeRentals)->values()->first();
        if (! $rental) {
            return;
        }
        $tenant = User::find($rental->tenant_id);
        $rooms = [
            ['direct', ChatRoomType::Direct, [$landlord, $tenant], null],
            ['group', ChatRoomType::Group, [$landlord, $tenant, User::find(collect($this->activeRentals)->values()->get(1)?->tenant_id)], null],
            ['support', ChatRoomType::Support, [$support, $tenant], now()],
        ];

        foreach ($rooms as [$key, $type, $participants, $archived]) {
            $room = ChatRoom::withoutGlobalScopes()->firstOrCreate(
                ['created_by_id' => $landlord->id, 'type' => $type],
                ['archived_at' => $archived],
            );
            if ($archived && ! $room->archived_at) {
                $room->update(['archived_at' => $archived]);
            }
            $ids = collect($participants)->filter()->mapWithKeys(fn (User $user) => [$user->id => ['is_muted' => false]])->all();
            $room->participants()->syncWithoutDetaching($ids);

            $messages = [
                [ChatMessageType::Text, 'សួស្តី — Hello from the RentWise demo.', null],
                [ChatMessageType::Image, 'រូបថតបន្ទប់ — Room photo', '/storage/demo-room.jpg'],
                [ChatMessageType::File, 'កិច្ចសន្យាជួល — Lease agreement', '/storage/demo-lease.pdf'],
                [ChatMessageType::System, 'អ្នកជួលបានចូលរួម — Tenant joined', null],
            ];
            foreach ($messages as $i => [$messageType, $body, $file]) {
                ChatMessage::firstOrCreate(['chat_room_id' => $room->id, 'user_id' => $participants[$i % count($participants)]->id, 'type' => $messageType, 'body' => $body], ['file_url' => $file, 'is_read' => $i % 2 === 0]);
            }
        }
    }
}
