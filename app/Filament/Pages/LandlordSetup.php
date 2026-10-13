<?php

namespace App\Filament\Pages;

use App\Enums\BillingType;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Filament\Resources\PropertyUtilityResource;
use App\Models\LandlordProfile;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Services\DefaultPropertyUtilitiesService;
use App\Services\RoomGridService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Setup wizard for a self-registered landlord (/app/setup), steps 2–5 after
 * the /register account step: properties → utilities → rooms & prices → review.
 *
 * Every "Next" writes that step to the real tables and advances
 * LandlordProfile::onboarding_step, so closing the app mid-way loses nothing
 * and RedirectToLandlordSetup brings the landlord back to the same step. Going
 * Back edits the saved rows (matched by id) instead of creating duplicates.
 *
 * Mobile-first markup with hand-written `rw-setup-*` CSS in rentwise-admin.css —
 * Filament's precompiled Tailwind doesn't carry arbitrary utilities.
 */
class LandlordSetup extends Page
{
    protected static string $view = 'filament.pages.landlord-setup';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static ?string $slug = 'setup';

    protected static bool $shouldRegisterNavigation = false;

    /** Session flag set by skip(): stop redirecting here for this session. */
    public const SKIP_SESSION_KEY = 'landlord_setup_skipped';

    public int $step = OnboardingStep::Properties->value;

    /** @var list<array{id: ?int, name: string, property_type: int|string, address_line: ?string, city: ?string, district: ?string, commune: ?string, village: ?string, currency: string}> */
    public array $properties = [];

    /** @var array<int, list<array{id: ?int, name: string, billing_type: int|string, unit_of_measure: string, rate: mixed}>> keyed by property id */
    public array $utilities = [];

    /** @var array<int, array{floors: mixed, per_floor: mixed, price: mixed, grid: list<array{id: ?int, number: string, floor: string, floor_default: mixed, price: mixed}>}> keyed by property id */
    public array $rooms = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->hasRole('landlord') && $user->landlordProfile?->onboarding_step !== null;
    }

    public function getTitle(): string
    {
        return __('Set up your rentals');
    }

    protected function getLayoutData(): array
    {
        return ['maxWidth' => MaxWidth::TwoExtraLarge];
    }

    public function mount()
    {
        $profile = $this->profile();

        if ($profile->onboarding_completed_at !== null) {
            return redirect()->route('filament.landlord.pages.dashboard');
        }

        $this->step = $profile->onboarding_step->value;
        $this->loadState();
    }

    // ---------------------------------------------------------------------
    // Navigation
    // ---------------------------------------------------------------------

    public function next(): void
    {
        match ($this->currentStep()) {
            OnboardingStep::Properties => $this->saveProperties(),
            OnboardingStep::Utilities => $this->saveUtilities(),
            OnboardingStep::Rooms => $this->saveRooms(),
            OnboardingStep::Review => null,
        };

        $this->goTo($this->currentStep()->next());
    }

    public function back(): void
    {
        $this->goTo($this->currentStep()->previous());
    }

    public function finish()
    {
        $this->profile()->update([
            'onboarding_step' => OnboardingStep::Review,
            'onboarding_completed_at' => now(),
        ]);

        if ($first = Property::query()->orderBy('id')->value('id')) {
            ActiveProperty::set($first);
        }

        return redirect()->route('filament.landlord.pages.dashboard');
    }

    /** "Finish later": leave the wizard for this session; the next sign-in resumes it. */
    public function skip()
    {
        session()->put(self::SKIP_SESSION_KEY, true);

        return redirect()->route('filament.landlord.pages.dashboard');
    }

    protected function goTo(OnboardingStep $step): void
    {
        $this->resetValidation();
        $this->step = $step->value;
        $this->persistStep($step);
        $this->loadState();
        $this->dispatch('rw-setup-step-changed');
    }

    /** Remember the step so the next sign-in resumes here (the add-property subclass doesn't). */
    protected function persistStep(OnboardingStep $step): void
    {
        $this->profile()->update(['onboarding_step' => $step]);
    }

    /** The properties this wizard run works on. */
    protected function wizardProperties(): Builder
    {
        return Property::query();
    }

    /** A saved property by id, scoped to this run (LandlordScope already blocks other landlords). */
    protected function findProperty(mixed $id): ?Property
    {
        return filled($id) ? $this->wizardProperties()->find($id) : null;
    }

    /** Cards removed in the wizard. Only still-empty setups are deleted — real data stays. */
    protected function removeUnlistedProperties(array $keptIds): void
    {
        Property::query()
            ->whereNotIn('id', $keptIds)
            ->whereDoesntHave('rentals')
            ->get()
            ->each(function (Property $property) {
                $property->units()->get()->each->delete();
                $property->propertyUtilities()->get()->each->delete();
                $property->delete();
            });
    }

    protected function afterPropertySaved(Property $property): void {}

    /** True for the single-property "Add property" screen (see AddProperty). */
    public function isAddingProperty(): bool
    {
        return false;
    }

    // ---------------------------------------------------------------------
    // Step 2 — properties
    // ---------------------------------------------------------------------

    /** Reset the lower address levels when a parent select changes (province → district → commune → village). */
    public function updatedProperties($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        $children = match ($field) {
            'city' => ['district', 'commune', 'village'],
            'district' => ['commune', 'village'],
            'commune' => ['village'],
            default => [],
        };

        foreach ($children as $child) {
            $this->properties[$index][$child] = '';
        }
    }

    public function addProperty(): void
    {
        $this->properties[] = $this->blankProperty();
    }

    public function removeProperty(int $index): void
    {
        if (count($this->properties) > 1) {
            unset($this->properties[$index]);
            $this->properties = array_values($this->properties);
        }
    }

    protected function saveProperties(): void
    {
        $this->validate([
            'properties' => ['required', 'array', 'min:1'],
            'properties.*.name' => ['required', 'string', 'max:255'],
            'properties.*.property_type' => ['required', Rule::in(array_keys(PropertyType::options()))],
            'properties.*.address_line' => ['nullable', 'string', 'max:255'],
            'properties.*.city' => ['nullable', 'string', 'max:255'],
            'properties.*.district' => ['nullable', 'string', 'max:255'],
            'properties.*.commune' => ['nullable', 'string', 'max:255'],
            'properties.*.village' => ['nullable', 'string', 'max:255'],
            'properties.*.currency' => ['required', Rule::in(['USD', 'KHR'])],
        ], [], [
            'properties.*.name' => __('Property name'),
            'properties.*.property_type' => __('Property type'),
            'properties.*.currency' => __('Currency'),
        ]);

        DB::transaction(function () {
            $keptIds = [];

            foreach ($this->properties as $index => $row) {
                $attributes = [
                    'name' => trim($row['name']),
                    'property_type' => PropertyType::from((int) $row['property_type']),
                    'address_line' => filled($row['address_line'] ?? null) ? trim($row['address_line']) : null,
                    'city' => filled($row['city'] ?? null) ? trim($row['city']) : null,
                    'district' => filled($row['district'] ?? null) ? trim($row['district']) : null,
                    'commune' => filled($row['commune'] ?? null) ? trim($row['commune']) : null,
                    'village' => filled($row['village'] ?? null) ? trim($row['village']) : null,
                ];

                // LandlordScope makes a tampered id resolve to nothing → treated as new.
                $property = $this->findProperty($row['id'] ?? null);

                if ($property) {
                    $property->update($attributes);
                } else {
                    $property = Property::create($attributes);
                }

                $this->properties[$index]['id'] = $property->id;
                $keptIds[] = $property->id;
                $this->afterPropertySaved($property);

                $this->syncCurrency($property, $row['currency']);
                app(DefaultPropertyUtilitiesService::class)->seed($property);
            }

            $this->removeUnlistedProperties($keptIds);
        });
    }

    /** Set the property's currency; during onboarding, carry it to its utilities and rooms too. */
    protected function syncCurrency(Property $property, string $currency): void
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

    // ---------------------------------------------------------------------
    // Step 3 — utilities
    // ---------------------------------------------------------------------

    public function addUtility(int $propertyId): void
    {
        $this->utilities[$propertyId][] = [
            'id' => null,
            'name' => '',
            'billing_type' => BillingType::Flat->value,
            'unit_of_measure' => __('month'),
            'rate' => '',
        ];
    }

    public function removeUtility(int $propertyId, int $index): void
    {
        unset($this->utilities[$propertyId][$index]);
        $this->utilities[$propertyId] = array_values($this->utilities[$propertyId] ?? []);
    }

    protected function saveUtilities(): void
    {
        $this->validate([
            'utilities.*.*.name' => ['required', 'string', 'max:100'],
            'utilities.*.*.billing_type' => ['required', Rule::in(array_keys(BillingType::options()))],
            'utilities.*.*.unit_of_measure' => ['required', 'string', 'max:30'],
            'utilities.*.*.rate' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], [], [
            'utilities.*.*.name' => __('Name'),
            'utilities.*.*.unit_of_measure' => __('Unit of measure'),
            'utilities.*.*.rate' => __('Price'),
        ]);

        DB::transaction(function () {
            foreach ($this->ownedPropertyIds() as $propertyId) {
                $keptIds = [];

                foreach ($this->utilities[$propertyId] ?? [] as $row) {
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
            }
        });
    }

    // ---------------------------------------------------------------------
    // Step 4 — rooms & prices
    // ---------------------------------------------------------------------

    /** Build (or rebuild) a property's room grid: floors × rooms per floor at one default price. */
    public function generateRooms(int $propertyId): void
    {
        $this->validate([
            "rooms.{$propertyId}.floors" => ['required', 'integer', 'min:1', 'max:50'],
            "rooms.{$propertyId}.per_floor" => ['required', 'integer', 'min:1', 'max:100'],
            "rooms.{$propertyId}.price" => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], [], [
            "rooms.{$propertyId}.floors" => __('Floors'),
            "rooms.{$propertyId}.per_floor" => __('Rooms per floor'),
            "rooms.{$propertyId}.price" => __('Price per room'),
        ]);

        $config = $this->rooms[$propertyId];
        $current = $config['grid'] ?? [];

        $floors = [];
        for ($floor = 1; $floor <= (int) $config['floors']; $floor++) {
            $floors[] = ['floor' => $floor, 'rooms' => (int) $config['per_floor'], 'default_price' => $config['price']];
        }

        $idsByNumber = collect($current)->filter(fn ($row) => filled($row['id'] ?? null))->pluck('id', 'number');

        $this->rooms[$propertyId]['grid'] = array_map(
            fn (array $row) => ['id' => $idsByNumber[$row['number']] ?? null] + $row,
            RoomGridService::build(['numbering' => 'floor_seq', 'floors' => $floors], $current),
        );
    }

    public function addRoom(int $propertyId): void
    {
        $this->rooms[$propertyId]['grid'][] = [
            'id' => null,
            'number' => '',
            'floor' => '',
            'floor_default' => null,
            'price' => $this->rooms[$propertyId]['price'] ?? '',
        ];
    }

    public function removeRoom(int $propertyId, int $index): void
    {
        unset($this->rooms[$propertyId]['grid'][$index]);
        $this->rooms[$propertyId]['grid'] = array_values($this->rooms[$propertyId]['grid'] ?? []);
    }

    protected function saveRooms(): void
    {
        $rules = [];
        $attributes = [];

        foreach ($this->ownedPropertyIds() as $propertyId) {
            $rules["rooms.{$propertyId}.grid"] = ['required', 'array', 'min:1'];
            $rules["rooms.{$propertyId}.grid.*.number"] = ['required', 'string', 'max:50', 'distinct'];
            $rules["rooms.{$propertyId}.grid.*.price"] = ['required', 'numeric', 'min:0', 'max:9999999'];
            $attributes["rooms.{$propertyId}.grid"] = __('Rooms');
            $attributes["rooms.{$propertyId}.grid.*.number"] = __('Room number');
            $attributes["rooms.{$propertyId}.grid.*.price"] = __('Price');
        }

        $this->validate($rules, [
            'rooms.*.grid.required' => __('Add at least one room — press Generate rooms.'),
            'rooms.*.grid.min' => __('Add at least one room — press Generate rooms.'),
        ], $attributes);

        DB::transaction(function () {
            foreach ($this->ownedPropertyIds() as $propertyId) {
                $property = Property::query()->findOrFail($propertyId);
                $currency = Money::normalize(PropertySetting::query()->where('property_id', $propertyId)->value('currency'));
                $keptIds = [];

                foreach ($this->rooms[$propertyId]['grid'] as $index => $row) {
                    $fields = [
                        'room_number' => trim((string) $row['number']),
                        'floor_number' => filled($row['floor'] ?? null) ? (string) $row['floor'] : null,
                        'rent_amount' => $row['price'],
                        'rent_currency' => $currency,
                    ];

                    $unit = filled($row['id'] ?? null)
                        ? Unit::query()->where('property_id', $propertyId)->find($row['id'])
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

                    $this->rooms[$propertyId]['grid'][$index]['id'] = $unit->id;
                    $keptIds[] = $unit->id;
                }

                Unit::query()
                    ->where('property_id', $propertyId)
                    ->whereNotIn('id', $keptIds)
                    ->whereDoesntHave('rentals')
                    ->get()
                    ->each->delete();
            }
        });
    }

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    protected function loadState(): void
    {
        $properties = $this->wizardProperties()->with('settings')->orderBy('id')->get();

        $this->properties = $properties->map(fn (Property $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'property_type' => $p->property_type?->value ?? PropertyType::Apartment->value,
            'address_line' => $p->address_line,
            'city' => $p->city,
            'district' => $p->district,
            'commune' => $p->commune,
            'village' => $p->village,
            'currency' => Money::normalize($p->settings?->currency),
        ])->all() ?: [$this->blankProperty()];

        $this->utilities = [];
        $this->rooms = [];

        foreach ($properties as $property) {
            $this->utilities[$property->id] = PropertyUtility::query()
                ->where('property_id', $property->id)
                ->orderBy('id')
                ->get()
                ->map(fn (PropertyUtility $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'billing_type' => $u->billing_type?->value,
                    'unit_of_measure' => $u->unit_of_measure,
                    'rate' => (float) $u->rate == 0.0 ? '' : (float) $u->rate,
                ])
                ->all();

            $units = Unit::query()->where('property_id', $property->id)->orderBy('id')->get();

            $this->rooms[$property->id] = [
                'floors' => $units->isEmpty() ? 1 : max(1, (int) $units->max(fn (Unit $u) => (int) $u->floor_number)),
                'per_floor' => $units->isEmpty() ? 5 : max(1, $units->countBy('floor_number')->max()),
                'price' => $units->isEmpty() ? '' : (float) $units->first()->rent_amount,
                'grid' => $units->map(fn (Unit $u) => [
                    'id' => $u->id,
                    'number' => $u->room_number,
                    'floor' => (string) $u->floor_number,
                    'floor_default' => null,
                    'price' => (float) $u->rent_amount,
                ])->values()->all(),
            ];
        }
    }

    protected function blankProperty(): array
    {
        return [
            'id' => null,
            'name' => '',
            'property_type' => PropertyType::Apartment->value,
            'address_line' => '',
            'city' => '',
            'district' => '',
            'commune' => '',
            'village' => '',
            'currency' => 'USD',
        ];
    }

    /** @return list<int> the saved properties' ids — from the DB, never the client. */
    protected function ownedPropertyIds(): array
    {
        return $this->wizardProperties()->orderBy('id')->pluck('id')->all();
    }

    protected function profile(): LandlordProfile
    {
        $profile = Auth::user()?->landlordProfile;

        abort_unless($profile?->onboarding_step !== null, 403);

        return $profile;
    }

    public function currentStep(): OnboardingStep
    {
        return OnboardingStep::from($this->step);
    }

    public function currencySymbol(int $propertyId): string
    {
        return Money::symbol(collect($this->properties)->firstWhere('id', $propertyId)['currency'] ?? null);
    }

    public function propertyName(int $propertyId): string
    {
        return (string) (collect($this->properties)->firstWhere('id', $propertyId)['name'] ?? '');
    }

    protected function getViewData(): array
    {
        return [
            'propertyTypes' => PropertyType::options(),
            'billingTypes' => BillingType::options(),
            'utilityNames' => PropertyUtilityResource::utilityOptions(),
        ];
    }
}
