<?php

namespace Database\Seeders;

use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Models\LandlordProfile;
use App\Models\Property;
use App\Models\Rental;
use App\Models\TenantProfile;
use App\Models\Unit;
use App\Models\User;
use App\Models\PropertyUtility;
use App\Models\UtilityUsage;
use App\Services\InvoiceBuilderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * End-to-end demo data that exercises the whole core stack (models, scopes,
 * services, ledger). Gated behind SEED_DEMO=true. Runs with no auth context,
 * so landlord_id is always set explicitly (the global scope no-ops in CLI).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // --- Landlord ---------------------------------------------------------
        $landlord = User::firstOrCreate(
            ['email' => 'landlord@rentwise.test'],
            ['name' => 'Dara Landlord', 'password' => 'password'],
        );
        $landlord->status = UserStatus::Active;
        $landlord->saveQuietly();
        $landlord->syncRoles(['landlord']);
        LandlordProfile::firstOrCreate(
            ['user_id' => $landlord->id],
            ['company_name' => 'Dara Rentals', 'can_create_tenants' => true],
        );

        // --- Tenant -----------------------------------------------------------
        $tenant = User::firstOrCreate(
            ['email' => 'tenant@rentwise.test'],
            ['name' => 'Sokha Tenant', 'password' => 'password', 'phone_number' => '012345678'],
        );
        $tenant->status = UserStatus::Active;
        $tenant->created_by_id = $landlord->id;
        $tenant->username = $tenant->username ?: 'tenant'; // tenant-portal login
        $tenant->saveQuietly();
        $tenant->syncRoles(['tenant']);
        TenantProfile::firstOrCreate(
            ['user_id' => $tenant->id],
            ['occupation' => 'Teacher', 'monthly_income' => 800],
        );

        // --- Property + pricing + units --------------------------------------
        $property = Property::firstOrCreate(
            ['landlord_id' => $landlord->id, 'name' => 'Riverside Residences'],
            ['property_type' => 1, 'city' => 'Phnom Penh', 'district' => 'Chamkarmon'],
        );

        $unit = Unit::firstOrCreate(
            ['property_id' => $property->id, 'room_number' => 'A-101'],
            [
                'landlord_id' => $landlord->id,
                'floor_number' => '1',
                'room_type' => 'Studio',
                'rent_amount' => 250,
                'status' => UnitStatus::Occupied,
            ],
        );

        // Link this room's login account to the demo tenant (portal demo).
        if (! $unit->account_user_id) {
            $unit->account_user_id = $tenant->id;
            $unit->saveQuietly();
        }

        // --- Rental (triggers the active_unit_id guard) ----------------------
        $rental = Rental::firstOrCreate(
            ['unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => RentalStatus::Active->value],
            [
                'landlord_id' => $landlord->id,
                'monthly_rent' => 250,
                'security_deposit' => 250,
                'start_date' => Carbon::now()->startOfMonth(),
            ],
        );

        // --- Property utilities (per-property) + a reading -------------------
        $electricity = PropertyUtility::firstOrCreate(
            ['property_id' => $property->id, 'name' => 'Electricity'],
            ['landlord_id' => $landlord->id, 'unit_of_measure' => 'kWh', 'rate' => 0.25, 'provider' => 'EDC'],
        );
        PropertyUtility::firstOrCreate(
            ['property_id' => $property->id, 'name' => 'Water'],
            ['landlord_id' => $landlord->id, 'unit_of_measure' => 'm³', 'rate' => 0.30, 'provider' => 'PPWSA'],
        );

        $usage = UtilityUsage::firstOrCreate(
            ['property_utility_id' => $electricity->id, 'unit_id' => $unit->id, 'rental_id' => $rental->id, 'reading_date' => Carbon::now()->startOfMonth()],
            [
                'landlord_id' => $landlord->id,
                'recorded_by_id' => $landlord->id,
                'reading_type' => ReadingType::Actual,
                'old_reading' => 1000,
                'new_reading' => 1120,
                'amount_used' => 120,
            ],
        );

        // --- Invoice (rent + utility) via the centralized builder, then a payment
        if ($rental->invoices()->count() === 0) {
            $invoice = app(InvoiceBuilderService::class)->create([
                'rental' => $rental,
                'period_start' => Carbon::now()->startOfMonth(),
                'period_end' => Carbon::now()->endOfMonth(),
                'usages' => [$usage],
            ]);

            // partial payment through the ledger (recomputes amount_paid + status)
            $invoice->recordPayment([
                'recorded_by_id' => $landlord->id,
                'amount' => 100,
            ]);
        }
    }
}
