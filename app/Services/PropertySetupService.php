<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creating a property and its first utilities and rooms — the writes behind the
 * signup wizard (Filament\Pages\LandlordSetup, and AddProperty which extends it)
 * and the /m "Add property" screen (Livewire\Mobile\AddProperty).
 *
 * The wizard saves one step at a time (saveProperty → saveUtilities →
 * saveRooms); the phone screen does all three in one go through create(). Both
 * end in the same rows, so a property added on a phone is indistinguishable from
 * one added on a desktop.
 *
 * Subscription caps are not checked here: Property and Unit enforce them in
 * their own creating hooks, which throw a ValidationException keyed
 * `subscription` — inside create()'s transaction that rolls the whole property
 * back.
 */
class PropertySetupService
{
    /**
     * Whether this user may add a property of their own: landlords and their
     * managers with the create permission. Platform staff have no landlord of
     * their own — a property made by them would have no owner (landlord_id is
     * auto-filled from the signed-in landlord) — so they add properties for a
     * chosen landlord in the admin panel instead.
     */
    public static function canAdd(?User $user): bool
    {
        return $user !== null
            && $user->effectiveLandlordId() !== null
            && $user->can('create', Property::class);
    }

    /** @return list<string> the currencies a property can bill in */
    public static function currencies(): array
    {
        return ['USD', 'KHR'];
    }

    /**
     * A property's own columns from a form row — trimmed, blank address parts
     * stored as null.
     *
     * @param  array{name: string, property_type: int|string, address_line?: ?string, city?: ?string, district?: ?string, commune?: ?string, village?: ?string}  $row
     */
    public static function propertyAttributes(array $row): array
    {
        $optional = fn (string $key): ?string => filled($row[$key] ?? null) ? trim((string) $row[$key]) : null;

        return [
            'name' => trim((string) $row['name']),
            'property_type' => PropertyType::from((int) $row['property_type']),
            'address_line' => $optional('address_line'),
            'city' => $optional('city'),
            'district' => $optional('district'),
            'commune' => $optional('commune'),
            'village' => $optional('village'),
        ];
    }

    /**
     * Create (or, given $existing, update) a property from a form row, set its
     * currency and give a new one the default utilities.
     *
     * @param  array{name: string, property_type: int|string, currency: string}  $row
     */
    public function saveProperty(array $row, ?Property $existing = null): Property
    {
        return DB::transaction(function () use ($row, $existing): Property {
            $attributes = static::propertyAttributes($row);

            if ($existing) {
                $existing->update($attributes);
                $property = $existing;
            } else {
                $property = Property::create($attributes);
            }

            $this->syncCurrency($property, $row['currency']);
            app(DefaultPropertyUtilitiesService::class)->seed($property);

            return $property;
        });
    }

    /** Set the property's currency; while it has no tenancy yet, carry it to its utilities and rooms too. */
    public function syncCurrency(Property $property, string $currency): void
    {
        $setting = PropertySetting::query()->firstOrNew(['property_id' => $property->id]);
        $changed = $setting->currency !== $currency;
        $setting->currency = $currency;
        $setting->save();

        if ($changed && ! $property->rentals()->exists()) {
            PropertyUtility::query()->where('property_id', $property->id)->update(['currency' => $currency]);
            Unit::query()->where('property_id', $property->id)->update(['rent_currency' => $currency]);
        }
    }

    /**
     * Make the property's utilities exactly $rows: matched by id are updated,
     * new ones created, the rest deleted.
     *
     * @param  list<array{id?: ?int, name: string, billing_type: int|string, unit_of_measure: string, rate: mixed}>  $rows
     */
    public function saveUtilities(int $propertyId, array $rows): void
    {
        DB::transaction(function () use ($propertyId, $rows): void {
            $keptIds = [];

            foreach ($rows as $row) {
                $attributes = [
                    'name' => trim($row['name']),
                    'billing_type' => BillingType::from((int) $row['billing_type']),
                    'unit_of_measure' => trim($row['unit_of_measure']),
                    'rate' => $row['rate'],
                    'is_active' => true,
                ];

                $utility = filled($row['id'] ?? null)
                    ? PropertyUtility::query()->where('property_id', $propertyId)->find($row['id'])
                    : null;

                if ($utility) {
                    $utility->update($attributes);
                } else {
                    $utility = PropertyUtility::create($attributes + ['property_id' => $propertyId]);
                }

                $keptIds[] = $utility->id;
            }

            PropertyUtility::query()
                ->where('property_id', $propertyId)
                ->whereNotIn('id', $keptIds)
                ->get()
                ->each->delete();
        });
    }

    /**
     * A floors × rooms-per-floor grid at one price (101, 102 · 201 …). Rooms of
     * $current keep their id and any price changed by hand.
     *
     * @param  list<array{id?: ?int, number: string, floor?: string, floor_default?: mixed, price: mixed}>  $current
     * @return list<array{id: ?int, number: string, floor: string, floor_default: ?float, price: float}>
     */
    public static function roomGrid(int $floors, int $perFloor, mixed $price, array $current = []): array
    {
        $floorRows = [];
        for ($floor = 1; $floor <= $floors; $floor++) {
            $floorRows[] = ['floor' => $floor, 'rooms' => $perFloor, 'default_price' => $price];
        }

        $idsByNumber = collect($current)->filter(fn ($row) => filled($row['id'] ?? null))->pluck('id', 'number');

        return array_map(
            fn (array $row) => ['id' => $idsByNumber[$row['number']] ?? null] + $row,
            RoomGridService::build(['numbering' => 'floor_seq', 'floors' => $floorRows], $current),
        );
    }

    /**
     * Make the property's rooms exactly $grid: matched by id are updated, new
     * ones created, and rooms left out deleted — unless they ever had a tenancy.
     *
     * @param  list<array{id?: ?int, number: string, floor?: ?string, price: mixed}>  $grid
     * @return array<int, int> the saved room's id for each grid index
     */
    public function saveRooms(Property $property, array $grid): array
    {
        return DB::transaction(function () use ($property, $grid): array {
            $currency = Money::normalize(PropertySetting::query()->where('property_id', $property->id)->value('currency'));
            $ids = [];

            foreach ($grid as $index => $row) {
                $fields = [
                    'room_number' => trim((string) $row['number']),
                    'floor_number' => filled($row['floor'] ?? null) ? (string) $row['floor'] : null,
                    'rent_amount' => $row['price'],
                    'rent_currency' => $currency,
                ];

                $unit = filled($row['id'] ?? null)
                    ? Unit::query()->where('property_id', $property->id)->find($row['id'])
                    : null;

                if ($unit) {
                    $unit->update($fields);
                } else {
                    $unit = Unit::create($fields + [
                        'property_id' => $property->id,
                        'landlord_id' => $property->landlord_id,
                        'room_type' => 'Room',
                        'status' => UnitStatus::Available,
                    ]);
                }

                $ids[$index] = $unit->id;
            }

            Unit::query()
                ->where('property_id', $property->id)
                ->whereNotIn('id', $ids)
                ->whereDoesntHave('rentals')
                ->get()
                ->each->delete();

            return $ids;
        });
    }

    /**
     * Everything at once (the /m screen): the property, its currency, the
     * default utilities at the given prices, and — when $rooms is given — a
     * floors × rooms-per-floor grid at one price. All or nothing: a plan limit
     * hit by the rooms leaves no half-made property behind.
     *
     * @param  array{name: string, property_type: int|string, currency: string}  $details
     * @param  array<string, mixed>  $utilityRates  default utility name (e.g. "Electricity") => price; blank stays 0
     * @param  ?array{floors: int, per_floor: int, price: mixed}  $rooms
     */
    public function create(array $details, array $utilityRates = [], ?array $rooms = null): Property
    {
        return DB::transaction(function () use ($details, $utilityRates, $rooms): Property {
            $property = $this->saveProperty($details);

            foreach ($property->propertyUtilities()->get() as $utility) {
                $rate = $utilityRates[$utility->name] ?? null;

                if (filled($rate)) {
                    $utility->update(['rate' => $rate]);
                }
            }

            if ($rooms !== null) {
                $this->saveRooms($property, static::roomGrid((int) $rooms['floors'], (int) $rooms['per_floor'], $rooms['price']));
            }

            return $property;
        });
    }
}
