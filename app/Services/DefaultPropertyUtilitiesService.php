<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Filament\Widgets\PropertySetupChecklistWidget;
use App\Models\Property;
use App\Models\PropertyUtility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives a brand-new property the two utilities every Cambodian rental has:
 * electricity and water, both metered.
 *
 * Until this existed, a freshly created property had *zero* `property_utilities`
 * rows and nothing said so — the billing run happily produced rent-only invoices
 * and the landlord had to discover, from the missing lines, that a catalog they
 * were never told about was empty.
 *
 * The rate is deliberately left at 0: only the landlord knows what EDC/PPWSA
 * charges them, and a wrong default silently bills wrong money. A rate of 0 is
 * what {@see PropertySetupChecklistWidget} flags as the
 * next unfinished step, so the gap is visible rather than guessed at.
 *
 * Currency is left unset on purpose too — {@see PropertyUtility::booted()}
 * derives it from the property's own settings.
 */
final class DefaultPropertyUtilitiesService
{
    /**
     * The starter catalog. Mirrors PropertyUtilityResource's own per-name
     * defaults (kWh/m³, metered) so a seeded row is indistinguishable from a
     * hand-created one.
     *
     * @return array<int, array{name: string, unit_of_measure: string, billing_type: BillingType}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Electricity', 'unit_of_measure' => 'kWh', 'billing_type' => BillingType::Metered],
            ['name' => 'Water', 'unit_of_measure' => 'm³', 'billing_type' => BillingType::Metered],
        ];
    }

    /**
     * Seed the starter catalog, once.
     *
     * A property that already has any utility row — including a soft-deleted one,
     * so deleting "Water" stays deleted — is left completely alone. That makes
     * this safe to call from every create path (and twice from the same one).
     *
     * @return Collection<int, PropertyUtility> the rows actually created
     */
    public function seed(Property $property): Collection
    {
        if ($property->propertyUtilities()->withTrashed()->exists()) {
            return new Collection;
        }

        return DB::transaction(function () use ($property): Collection {
            $created = new Collection;

            foreach (static::defaults() as $default) {
                $created->push(PropertyUtility::create([
                    'property_id' => $property->getKey(),
                    // Explicit rather than left to BelongsToLandlord: platform
                    // staff creating for a landlord have no effectiveLandlordId.
                    'landlord_id' => $property->landlord_id,
                    'name' => $default['name'],
                    'unit_of_measure' => $default['unit_of_measure'],
                    'billing_type' => $default['billing_type'],
                    'rate' => 0,
                    'is_active' => true,
                ]));
            }

            return $created;
        });
    }
}
