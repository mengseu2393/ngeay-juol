<?php

namespace Database\Seeders;

use App\Enums\PropertyType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Models\LandlordProfile;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Services\RoomAccountService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One landlord with a 3-property portfolio. Each property has several units, and
 * each unit has a *sequential* tenant history — past tenants (Vacated) followed
 * by one current tenant (Active) — never overlapping, so the "one active tenancy
 * per unit" guard holds. Every tenancy gets its own login via
 * {@see RoomAccountService::createForRental} (one login per tenant).
 *
 * Idempotent: re-running skips a unit that already has tenancies. Run with:
 *   php artisan db:seed --class=Database\\Seeders\\LandlordPortfolioSeeder
 */
class LandlordPortfolioSeeder extends Seeder
{
    /** Pool of occupant names drawn on per unit for its tenant timeline. */
    private array $names = [
        'Sok Dara', 'Chan Sophea', 'Vichea Kim', 'Lyhour Meas', 'Sreyna Chea',
        'Rithy Pich', 'Bopha Nuon', 'Veasna Hong', 'Kanha Sok', 'Pisey Lim',
        'Samnang Ouk', 'Theary Sen', 'Dara Kong', 'Sovann Mao', 'Channary Yim',
        'Phalla Ros', 'Vannak Tep', 'Maly Heng', 'Visal Chhem', 'Sreypov Eng',
    ];

    /** Each property: name, type, city/district and how its units are laid out. */
    private array $blueprint = [
        [
            'name' => 'Riverside Residences', 'type' => PropertyType::Apartment,
            'city' => 'Phnom Penh', 'district' => 'Chamkarmon',
            'group' => ['Standard Studio', 'Studio', 220],
            'floors' => 2, 'unitsPerFloor' => 3, 'rent' => 220,
        ],
        [
            'name' => 'Sunrise Garden Villas', 'type' => PropertyType::Villa,
            'city' => 'Siem Reap', 'district' => 'Svay Dangkum',
            'group' => ['Family Villa', '2-Bedroom', 450],
            'floors' => 2, 'unitsPerFloor' => 2, 'rent' => 450,
        ],
        [
            'name' => 'City Center Condos', 'type' => PropertyType::Condo,
            'city' => 'Phnom Penh', 'district' => 'Daun Penh',
            'group' => ['Deluxe One-Bed', '1-Bedroom', 380],
            'floors' => 3, 'unitsPerFloor' => 2, 'rent' => 380,
        ],
    ];

    public function run(): void
    {
        $service = app(RoomAccountService::class);

        DB::transaction(function () use ($service) {
            $landlord = $this->landlord();

            foreach ($this->blueprint as $bp) {
                $property = Property::firstOrCreate(
                    ['landlord_id' => $landlord->id, 'name' => $bp['name']],
                    ['property_type' => $bp['type']->value, 'city' => $bp['city'], 'district' => $bp['district']],
                );

                [, $roomType] = $bp['group'];

                $nameCursor = 0;
                $prefix = strtoupper(Str::substr(Str::slug($bp['name']), 0, 1)); // R / S / C

                for ($floor = 1; $floor <= $bp['floors']; $floor++) {
                    for ($n = 1; $n <= $bp['unitsPerFloor']; $n++) {
                        $roomNumber = $prefix.'-'.$floor.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

                        $unit = Unit::firstOrCreate(
                            ['property_id' => $property->id, 'room_number' => $roomNumber],
                            [
                                'landlord_id' => $landlord->id,
                                'floor_number' => (string) $floor,
                                'room_type' => $roomType,
                                'rent_amount' => $bp['rent'],
                                'status' => UnitStatus::Occupied,
                            ],
                        );

                        // Skip units that already have a tenant timeline (idempotent re-run).
                        if ($unit->rentals()->withoutGlobalScopes()->exists()) {
                            continue;
                        }

                        $this->seedTenancies($unit, $landlord, $service, $nameCursor);
                    }
                }
            }
        });
    }

    /** The landlord login + profile. */
    private function landlord(): User
    {
        $landlord = User::firstOrCreate(
            ['email' => 'portfolio@rentwise.test'],
            ['name' => 'Vannak Landlord', 'username' => 'portfolio', 'password' => 'password'],
        );
        $landlord->forceFill(['status' => UserStatus::Active])->saveQuietly();
        $landlord->syncRoles(['landlord']);

        LandlordProfile::firstOrCreate(
            ['user_id' => $landlord->id],
            ['company_name' => 'Vannak Property Group', 'can_create_tenants' => true],
        );

        return $landlord;
    }

    /**
     * Build 2–3 back-to-back tenancies for a unit: the earliest ones Vacated, the
     * most recent one Active. Dates march forward without overlap so only a single
     * Active tenancy ever occupies the room.
     */
    private function seedTenancies(Unit $unit, User $landlord, RoomAccountService $service, int &$nameCursor): void
    {
        $count = 2 + ($unit->id % 2);            // 2 or 3 tenants per unit
        $cursor = Carbon::now()->startOfMonth()->subMonths(($count - 1) * 7 + 3);

        for ($i = 0; $i < $count; $i++) {
            $isCurrent = $i === $count - 1;
            $start = $cursor->copy();
            $end = $isCurrent ? null : $cursor->copy()->addMonths(6)->endOfMonth();
            $cursor = $isCurrent ? $cursor : $cursor->copy()->addMonths(7); // 1-month gap before next

            $name = $this->names[$nameCursor % count($this->names)];
            $nameCursor++;

            $rental = $unit->rentals()->make([
                'occupant_name' => $name,
                'occupant_phone' => '0'.(60 + ($nameCursor % 9)).str_pad((string) (100000 + $unit->id * 7 + $i), 6, '0', STR_PAD_LEFT),
                'occupant_id_card' => str_pad((string) (1000000 + $unit->id * 31 + $i), 9, '0', STR_PAD_LEFT),
                'status' => $isCurrent ? RentalStatus::Active->value : RentalStatus::Vacated->value,
                'monthly_rent' => $unit->rent_amount,
                'security_deposit' => $unit->rent_amount,
                'start_date' => $start,
                'end_date' => $end,
                'property_id' => $unit->property_id,
                'landlord_id' => $landlord->id,
            ]);
            $rental->setRelation('unit', $unit);

            // One login per tenant — mints a dedicated account and saves the tenancy.
            $service->createForRental($rental);
        }
    }
}
