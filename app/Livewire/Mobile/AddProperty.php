<?php

namespace App\Livewire\Mobile;

use App\Enums\PropertyType;
use App\Services\DefaultPropertyUtilitiesService;
use App\Services\PropertySetupService;
use App\Services\SubscriptionService;
use App\Support\ActiveProperty;
use App\Support\LocationHelper;
use App\Support\UtilityCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/settings/property/new — add a property from a phone. The panel's "Add
 * property" wizard (Filament\Pages\AddProperty) in one screen: name, type,
 * address and currency, the default utilities' prices, and optionally a first
 * set of rooms (floors × rooms per floor at one price).
 *
 * Nothing is written until Save, and then everything at once
 * (PropertySetupService::create) — a plan limit hit by the rooms leaves no
 * half-made property behind. The new property becomes the active one, with a
 * full page load so the top bar's (persisted) switcher shows it.
 */
#[Layout('mobile.layout')]
class AddProperty extends Component
{
    public string $name = '';

    public int|string $propertyType = PropertyType::Apartment->value;

    public string $currency = 'USD';

    public string $addressLine = '';

    public string $city = '';

    public string $district = '';

    public string $commune = '';

    public string $village = '';

    /** @var array<string, string> default utility name => price */
    public array $rates = [];

    public bool $withRooms = true;

    public string $floors = '1';

    public string $perFloor = '5';

    public string $roomPrice = '';

    public function mount(): void
    {
        abort_unless(PropertySetupService::canAdd(Auth::user()), 403);

        foreach (DefaultPropertyUtilitiesService::defaults() as $utility) {
            $this->rates[$utility['name']] = '';
        }
    }

    /** Reset the lower address levels when a parent changes (province → district → commune → village). */
    public function updated(string $property): void
    {
        $children = match ($property) {
            'city' => ['district', 'commune', 'village'],
            'district' => ['commune', 'village'],
            'commune' => ['village'],
            default => [],
        };

        foreach ($children as $child) {
            $this->{$child} = '';
        }
    }

    public function save(): void
    {
        abort_unless(PropertySetupService::canAdd(Auth::user()), 403);

        if (! SubscriptionService::canMutate(Auth::user())) {
            $this->dispatch('rw-m-toast', message: __('Write actions are disabled until payment is completed.'));

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'propertyType' => ['required', Rule::in(array_keys(PropertyType::options()))],
            'currency' => ['required', Rule::in(PropertySetupService::currencies())],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'commune' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:255'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'withRooms' => ['boolean'],
            'floors' => ['exclude_unless:withRooms,true', 'required', 'integer', 'min:1', 'max:50'],
            'perFloor' => ['exclude_unless:withRooms,true', 'required', 'integer', 'min:1', 'max:100'],
            'roomPrice' => ['exclude_unless:withRooms,true', 'required', 'numeric', 'min:0', 'max:9999999'],
        ], [], [
            'name' => __('Property name'),
            'propertyType' => __('Property type'),
            'currency' => __('Currency'),
            'addressLine' => __('Address'),
            'rates.*' => __('Price'),
            'floors' => __('Floors'),
            'perFloor' => __('Rooms per floor'),
            'roomPrice' => __('Price per room'),
        ]);

        try {
            $property = app(PropertySetupService::class)->create(
                [
                    'name' => $this->name,
                    'property_type' => $this->propertyType,
                    'currency' => $this->currency,
                    'address_line' => $this->addressLine,
                    'city' => $this->city,
                    'district' => $this->district,
                    'commune' => $this->commune,
                    'village' => $this->village,
                ],
                $this->rates,
                $this->withRooms
                    ? ['floors' => (int) $this->floors, 'per_floor' => (int) $this->perFloor, 'price' => $this->roomPrice]
                    : null,
            );
        } catch (ValidationException $e) {
            // The plan's property / room limit (keyed `subscription`).
            $this->setErrorBag($e->validator->errors());

            return;
        }

        ActiveProperty::set($property->id);

        $this->redirect(
            $this->withRooms ? route('mobile.rooms') : route('mobile.property-settings'),
            navigate: false,
        );
    }

    public function render()
    {
        return view('livewire.mobile.add-property', [
            'propertyTypes' => PropertyType::options(),
            'limitMessage' => $this->propertyLimitMessage(),
            'utilities' => collect(DefaultPropertyUtilitiesService::defaults())->map(fn (array $utility) => $utility + [
                'label' => UtilityCatalog::label($utility['name']),
            ]),
            'locations' => [
                'city' => LocationHelper::getProvinces(),
                'district' => LocationHelper::getDistricts($this->city ?: null),
                'commune' => LocationHelper::getCommunes($this->city ?: null, $this->district ?: null),
                'village' => LocationHelper::getVillages($this->city ?: null, $this->district ?: null, $this->commune ?: null),
            ],
        ])->title(__('Add property'));
    }

    /** The plan's "maximum properties" message when the landlord is already at it, so they know before typing. */
    private function propertyLimitMessage(): ?string
    {
        try {
            SubscriptionService::assertWithinPropertyCap(Auth::user());
        } catch (ValidationException $e) {
            return $e->errors()['subscription'][0] ?? null;
        }

        return null;
    }
}
