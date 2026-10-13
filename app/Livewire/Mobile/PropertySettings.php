<?php

namespace App\Livewire\Mobile;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Services\ErrorLogService;
use App\Services\OpeningReadingService;
use App\Services\PropertySettingsService;
use App\Services\PropertySetupService;
use App\Services\SubscriptionService;
use App\Support\ActiveProperty;
use App\Support\LocationHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * /m/settings/property — the active property's own settings on a phone: its
 * details and address, billing defaults, move-in rules, contacts, and the
 * opening meter readings. The phone version of the panel's Property Settings
 * page plus the property's Edit form (PropertyResource); the writes go through
 * the same services (PropertySettingsService, PropertySetupService,
 * OpeningReadingService).
 *
 * Grouped like a phone's settings app: each section is a card of saved values;
 * Edit opens that section in a bottom sheet, Save writes only that section.
 * Rooms live on the Rooms tab and utility prices on their own screen — linked,
 * not repeated here.
 */
#[Layout('mobile.layout')]
class PropertySettings extends Component
{
    /** The sheets on this screen; closing one without saving drops its edits. */
    public const SHEETS = ['prop-details', 'prop-billing', 'prop-move-in', 'prop-contacts', 'prop-opening'];

    #[Locked]
    public ?int $propertyId = null;

    /** @var array{name: string, property_type: int|string, address_line: string, city: string, district: string, commune: string, village: string} */
    public array $details = [];

    /** @var array{currency: string, invoice_prefix: string, due_day_of_month: mixed, invoice_due_days: mixed, late_fee: mixed, usd_khr_exchange_rate: mixed, exchange_rate_date: string} */
    public array $billing = [];

    /** @var array{first_month_billing_mode: string, proration_cutoff_day: mixed, require_first_month_upfront: bool, move_in_preset: string, create_invoice_on_move_in: bool, upfront_deposit_months: int|string} */
    public array $moveIn = [];

    /** @var array{caretaker_name: string, caretaker_phone: string, parking_info: string, insurance_info: string} */
    public array $contacts = [];

    public ?int $openingUtilityId = null;

    public string $openingDate = '';

    /** @var array<int|string, string> unit id => opening index */
    public array $openingReadings = [];

    public function mount(): void
    {
        $property = ActiveProperty::model();

        if (! $property) {
            return; // the screen asks to pick a property
        }

        Gate::authorize('view', $property);

        $this->propertyId = $property->id;
        $this->fillAll();
    }

    // ── Sections ────────────────────────────────────────────────────────────

    public function saveDetails(): void
    {
        $property = $this->writableProperty();
        if (! $property) {
            return;
        }

        $this->validate([
            'details.name' => ['required', 'string', 'max:255'],
            'details.property_type' => ['required', Rule::in(array_keys(PropertyType::options()))],
            'details.address_line' => ['nullable', 'string', 'max:255'],
            'details.city' => ['nullable', 'string', 'max:255'],
            'details.district' => ['nullable', 'string', 'max:255'],
            'details.commune' => ['nullable', 'string', 'max:255'],
            'details.village' => ['nullable', 'string', 'max:255'],
        ], [], [
            'details.name' => __('Property name'),
            'details.property_type' => __('Property type'),
            'details.address_line' => __('Address'),
            'details.city' => __('City / Province'),
            'details.district' => __('District (Khan / Srok)'),
            'details.commune' => __('Commune (Sangkat / Khum)'),
            'details.village' => __('Village (Phum)'),
        ]);

        $property->update(PropertySetupService::propertyAttributes($this->details));

        $this->saved('prop-details', __('Property details saved'));
    }

    /** Reset the lower address levels when a parent changes (province → district → commune → village). */
    public function updatedDetails($value, string $key): void
    {
        $children = match ($key) {
            'city' => ['district', 'commune', 'village'],
            'district' => ['commune', 'village'],
            'commune' => ['village'],
            default => [],
        };

        foreach ($children as $child) {
            $this->details[$child] = '';
        }
    }

    public function saveBilling(): void
    {
        $property = $this->writableProperty();
        if (! $property) {
            return;
        }

        $data = $this->validate([
            'billing.currency' => ['required', Rule::in(PropertySetupService::currencies())],
            'billing.invoice_prefix' => ['nullable', 'string', 'max:50'],
            'billing.due_day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'billing.invoice_due_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'billing.late_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'billing.usd_khr_exchange_rate' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'billing.exchange_rate_date' => ['nullable', 'date'],
        ], [], [
            'billing.currency' => __('Currency'),
            'billing.invoice_prefix' => __('Invoice prefix'),
            'billing.due_day_of_month' => __('Due day of month'),
            'billing.invoice_due_days' => __('Invoice Due Duration (Days)'),
            'billing.late_fee' => __('Late fee'),
            'billing.usd_khr_exchange_rate' => __('USD to KHR exchange rate'),
            'billing.exchange_rate_date' => __('Exchange rate date'),
        ])['billing'];

        $blankToNull = fn ($value) => filled($value) ? $value : null;

        app(PropertySettingsService::class)->save($this->setting(), [
            'currency' => $data['currency'],
            'invoice_prefix' => filled($data['invoice_prefix']) ? trim($data['invoice_prefix']) : null,
            'due_day_of_month' => $blankToNull($data['due_day_of_month']),
            'invoice_due_days' => $blankToNull($data['invoice_due_days']),
            'late_fee' => filled($data['late_fee']) ? $data['late_fee'] : 0,
            'usd_khr_exchange_rate' => $blankToNull($data['usd_khr_exchange_rate']),
            'exchange_rate_date' => $blankToNull($data['exchange_rate_date']),
        ]);

        $this->saved('prop-billing', __('Billing settings saved'));
    }

    /**
     * Monthly billing is an on/off switch right on the card — no sheet.
     * Returns whether it was saved, so the switch can flip back if not.
     */
    public function setMonthlyBilling(bool $enabled): bool
    {
        if (! $this->writableProperty()) {
            return false;
        }

        app(PropertySettingsService::class)->save($this->setting(), ['monthly_billing_enabled' => $enabled]);

        $this->dispatch('rw-m-toast', message: $enabled ? __('Monthly billing turned on') : __('Monthly billing turned off'));

        return true;
    }

    /** Today's NBC rate, saved straight away (like the panel's button); other unsaved fields stay as typed. */
    public function fetchExchangeRate(): void
    {
        if (! $this->writableProperty()) {
            return;
        }

        try {
            $payload = app(PropertySettingsService::class)->fetchExchangeRate($this->setting());
        } catch (Throwable $exception) {
            ErrorLogService::recordHandled($exception, 'Could not fetch exchange rate');
            $this->dispatch('rw-m-toast', message: __('Could not fetch exchange rate. Please try again, or enter the rate yourself.'));

            return;
        }

        $this->billing['usd_khr_exchange_rate'] = static::plainNumber($payload['usd_khr_exchange_rate']);
        $this->billing['exchange_rate_date'] = $payload['exchange_rate_date'];
        $this->resetValidation(['billing.usd_khr_exchange_rate', 'billing.exchange_rate_date']);

        $this->dispatch('rw-m-toast', message: __('Saved :rate KHR per 1 USD from :source.', [
            'rate' => number_format($payload['rate'], 2),
            'source' => $payload['exchange_rate_source'],
        ]));
    }

    public function saveMoveIn(): void
    {
        $property = $this->writableProperty();
        if (! $property) {
            return;
        }

        $halfMonth = FirstMonthBillingMode::HalfMonth->value;

        $data = $this->validate([
            'moveIn.first_month_billing_mode' => ['required', Rule::enum(FirstMonthBillingMode::class)],
            'moveIn.proration_cutoff_day' => ["required_if:moveIn.first_month_billing_mode,{$halfMonth}", 'nullable', 'integer', 'min:1', 'max:28'],
            'moveIn.require_first_month_upfront' => ['boolean'],
            'moveIn.move_in_preset' => ['nullable', Rule::in(array_keys(PropertySettingsService::moveInPresetOptions()))],
            'moveIn.create_invoice_on_move_in' => ['boolean'],
            'moveIn.upfront_deposit_months' => ['required', Rule::in(array_keys(PropertySettingsService::depositMonthOptions()))],
        ], [
            'moveIn.proration_cutoff_day.required_if' => __('Enter the cutoff day for half-month billing.'),
        ], [
            'moveIn.first_month_billing_mode' => __('First-month billing mode'),
            'moveIn.proration_cutoff_day' => __('Half-month cutoff day'),
            'moveIn.move_in_preset' => __('Move-in requirements preset'),
            'moveIn.upfront_deposit_months' => __('Security deposit'),
        ])['moveIn'];

        $state = [
            'first_month_billing_mode' => $data['first_month_billing_mode'],
            'require_first_month_upfront' => (bool) $data['require_first_month_upfront'],
            'move_in_preset' => filled($data['move_in_preset']) ? $data['move_in_preset'] : null,
            'create_invoice_on_move_in' => (bool) $data['create_invoice_on_move_in'],
            'upfront_deposit_months' => (int) $data['upfront_deposit_months'],
        ];

        // Like the panel, the cutoff only changes while half-month billing is chosen.
        if ($data['first_month_billing_mode'] === $halfMonth) {
            $state['proration_cutoff_day'] = (int) $data['proration_cutoff_day'];
        }

        app(PropertySettingsService::class)->save($this->setting(), $state);

        $this->saved('prop-move-in', __('Move-in rules saved'));
    }

    public function saveContacts(): void
    {
        $property = $this->writableProperty();
        if (! $property) {
            return;
        }

        $data = $this->validate([
            'contacts.caretaker_name' => ['nullable', 'string', 'max:255'],
            'contacts.caretaker_phone' => ['nullable', 'string', 'max:50'],
            'contacts.parking_info' => ['nullable', 'string', 'max:2000'],
            'contacts.insurance_info' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'contacts.caretaker_name' => __('Caretaker name'),
            'contacts.caretaker_phone' => __('Caretaker phone'),
            'contacts.parking_info' => __('Parking info'),
            'contacts.insurance_info' => __('Insurance info'),
        ])['contacts'];

        app(PropertySettingsService::class)->save(
            $this->setting(),
            array_map(fn ($value) => filled($value) ? trim($value) : null, $data),
        );

        $this->saved('prop-contacts', __('Contacts saved'));
    }

    // ── Opening readings ────────────────────────────────────────────────────

    public function updatedOpeningUtilityId(): void
    {
        $this->openingReadings = [];
        $this->resetValidation();
    }

    public function saveOpeningReadings(): void
    {
        if (! $this->writableProperty()) {
            return;
        }

        $this->validate([
            'openingUtilityId' => ['required', 'integer'],
            'openingDate' => ['required', 'date', 'before_or_equal:today'],
            'openingReadings.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [
            'openingDate.before_or_equal' => __('The reading date cannot be in the future.'),
        ], [
            'openingUtilityId' => __('Utility'),
            'openingDate' => __('Reading date'),
            'openingReadings.*' => __('Reading'),
        ]);

        $utility = $this->meteredUtilities()->firstWhere('id', $this->openingUtilityId);

        if (! $utility) {
            $this->addError('openingUtilityId', __('That utility is no longer available.'));

            return;
        }

        $result = app(OpeningReadingService::class)->apply(
            $utility,
            Carbon::parse($this->openingDate)->toDateString(),
            $this->openingReadings,
        );

        $recorded = $result['meters'] + $result['baselines'];

        if ($recorded === 0) {
            $this->addError('openingReadings', __('No readings entered.'));

            return;
        }

        $this->openingReadings = [];
        $this->dispatch('rw-m-sheet-close', name: 'prop-opening');
        $this->dispatch('rw-m-toast', message: __(':count opening reading(s) initialized', ['count' => $recorded]));
    }

    /** A sheet was closed without saving: put its fields back to what is saved. */
    public function discard(string $sheet): void
    {
        if (! $this->propertyId || ! in_array($sheet, self::SHEETS, true)) {
            return;
        }

        $this->resetValidation();

        match ($sheet) {
            'prop-opening' => $this->openingReadings = [],
            default => $this->fillAll(),
        };
    }

    public function render()
    {
        $property = $this->propertyId ? Property::query()->find($this->propertyId) : null;

        return view('livewire.mobile.property-settings', [
            'property' => $property,
            'setting' => $property ? $this->setting() : null,
            'propertyTypes' => PropertyType::options(),
            'currencies' => PropertySettingsService::currencyOptions(),
            'billingModes' => FirstMonthBillingMode::options(),
            'presets' => PropertySettingsService::moveInPresetOptions(),
            'depositMonths' => PropertySettingsService::depositMonthOptions(),
            'locations' => $property ? $this->locationOptions() : [],
            'meteredUtilities' => $property ? $this->meteredUtilities() : new Collection,
            'openingRows' => $property ? $this->openingRows() : collect(),
            'canAddProperty' => (bool) auth()->user()?->can('create', Property::class),
        ])->title(__('Property Settings'));
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** The property, when this user may change it right now; otherwise a toast and null. */
    private function writableProperty(): ?Property
    {
        $property = $this->propertyId ? Property::query()->find($this->propertyId) : null;
        abort_unless($property !== null, 404);

        if (! SubscriptionService::canMutate(auth()->user())) {
            $this->dispatch('rw-m-toast', message: __('Write actions are disabled until payment is completed.'));

            return null;
        }

        Gate::authorize('update', $property);

        return $property;
    }

    private function setting(): PropertySetting
    {
        return app(PropertySettingsService::class)->forProperty($this->propertyId);
    }

    private function saved(string $sheet, string $message): void
    {
        $this->fillAll();
        $this->dispatch('rw-m-sheet-close', name: $sheet);
        $this->dispatch('rw-m-toast', message: $message);
    }

    private function fillAll(): void
    {
        $property = Property::query()->findOrFail($this->propertyId);
        $setting = $this->setting();

        $this->details = [
            'name' => (string) $property->name,
            'property_type' => $property->property_type?->value ?? PropertyType::Apartment->value,
            'address_line' => (string) $property->address_line,
            'city' => (string) $property->city,
            'district' => (string) $property->district,
            'commune' => (string) $property->commune,
            'village' => (string) $property->village,
        ];

        $this->billing = [
            'currency' => $setting->currency ?: 'USD',
            'invoice_prefix' => (string) $setting->invoice_prefix,
            'due_day_of_month' => (string) $setting->due_day_of_month,
            'invoice_due_days' => (string) $setting->invoice_due_days,
            'late_fee' => $setting->late_fee === null ? '' : static::plainNumber($setting->late_fee),
            'usd_khr_exchange_rate' => $setting->usd_khr_exchange_rate === null ? '' : static::plainNumber($setting->usd_khr_exchange_rate),
            'exchange_rate_date' => (string) $setting->exchange_rate_date?->toDateString(),
        ];

        $this->moveIn = [
            'first_month_billing_mode' => ($setting->first_month_billing_mode ?? FirstMonthBillingMode::FullMonth)->value,
            'proration_cutoff_day' => (string) ($setting->proration_cutoff_day ?? 15),
            'require_first_month_upfront' => (bool) $setting->require_first_month_upfront,
            'move_in_preset' => (string) ($setting->move_in_preset ?? ''),
            'create_invoice_on_move_in' => (bool) $setting->create_invoice_on_move_in,
            'upfront_deposit_months' => (int) ($setting->upfront_deposit_months ?? 0),
        ];

        $this->contacts = [
            'caretaker_name' => (string) $setting->caretaker_name,
            'caretaker_phone' => (string) $setting->caretaker_phone,
            'parking_info' => (string) $setting->parking_info,
            'insurance_info' => (string) $setting->insurance_info,
        ];

        $this->openingUtilityId ??= $this->meteredUtilities()->first()?->getKey();
        $this->openingDate = now()->toDateString();
        $this->openingReadings = [];
    }

    /** Metered utilities of this property — the only kind with an opening index (same as the panel). */
    private function meteredUtilities(): Collection
    {
        return PropertyUtility::query()
            ->where('property_id', $this->propertyId)
            ->where('billing_type', BillingType::Metered->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function openingRows(): \Illuminate\Support\Collection
    {
        $utility = $this->openingUtilityId ? $this->meteredUtilities()->firstWhere('id', $this->openingUtilityId) : null;

        return $utility ? app(OpeningReadingService::class)->rows($utility)->values() : collect();
    }

    /**
     * The gazetteer's options for each address level, with the saved value kept
     * as an option even when it isn't in the gazetteer (typed in the panel).
     *
     * @return array{city: array<string, string>, district: array<string, string>, commune: array<string, string>, village: array<string, string>}
     */
    private function locationOptions(): array
    {
        $d = $this->details;
        $levels = [
            'city' => LocationHelper::getProvinces(),
            'district' => LocationHelper::getDistricts($d['city'] ?: null),
            'commune' => LocationHelper::getCommunes($d['city'] ?: null, $d['district'] ?: null),
            'village' => LocationHelper::getVillages($d['city'] ?: null, $d['district'] ?: null, $d['commune'] ?: null),
        ];

        foreach ($levels as $field => $options) {
            if (filled($d[$field]) && ! array_key_exists($d[$field], $options)) {
                $levels[$field] = [$d[$field] => $d[$field]] + $options;
            }
        }

        return $levels;
    }

    /** "4100.0000" → "4100", "1.50" → "1.5". */
    private static function plainNumber(mixed $value): string
    {
        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
