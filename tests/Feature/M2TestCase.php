<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Carbon\Carbon;

/**
 * Shared fixtures for the /m2 app's tests (one test class per screen). The /m2
 * flag is on by default here; the flag tests turn it off themselves. Reuses the
 * /m fixtures (landlord, property, rental, invoice, signIn) — they are about the
 * data, not the shell.
 */
abstract class M2TestCase extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 09:00:00');
        config(['m2.enabled' => true, 'm2.testers' => []]);
    }

    protected function utility(Property $property, string $name, BillingType $type = BillingType::Metered, float $rate = 0.25): PropertyUtility
    {
        return PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'name' => $name,
            'billing_type' => $type,
            'rate' => $rate,
            'unit_of_measure' => $name === 'Water' ? 'm³' : ($type === BillingType::Flat ? 'month' : 'kWh'),
            'is_active' => true,
        ]);
    }

    protected function reading(User $landlord, Unit $unit, PropertyUtility $utility, string $date, float $new = 100, float $used = 10): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => $date,
            'old_reading' => $new - $used,
            'new_reading' => $new,
            'amount_used' => $used,
        ]);
    }
}
