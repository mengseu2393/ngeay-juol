<?php

namespace App\Services;

use App\Enums\FirstMonthBillingMode;
use App\Models\PropertySetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A property's billing / move-in / contact settings (PropertySetting) — the
 * writes behind the panel's Property Settings page (Filament\Pages\PropertySettings)
 * and the /m screen (Livewire\Mobile\PropertySettings).
 *
 * Callers check SubscriptionService::canMutate() first; this only writes.
 */
class PropertySettingsService
{
    /** What a property's settings start as the first time anyone opens them. */
    public const DEFAULTS = [
        'currency' => 'USD',
        'due_day_of_month' => 7,
        'first_month_billing_mode' => FirstMonthBillingMode::FullMonth->value,
        'proration_cutoff_day' => 15,
        'require_first_month_upfront' => false,
        'upfront_deposit_months' => 0,
        'monthly_billing_enabled' => false,
        'invoice_due_days' => 7,
    ];

    /** The property's settings row, created with the defaults if it has none yet. */
    public function forProperty(int $propertyId): PropertySetting
    {
        $setting = PropertySetting::firstOrCreate(['property_id' => $propertyId], static::DEFAULTS);

        // A row created just now only carries the attributes above; reload it so
        // the columns' database defaults (late_fee = 0, …) are there too — a form
        // filled from it would otherwise save them back as NULL.
        return $setting->wasRecentlyCreated ? $setting->refresh() : $setting;
    }

    /**
     * Save the given fields. Picking a different move-in preset (other than
     * "custom") rebuilds the property's structured move-in rules; tenancies
     * already started keep the rules they were snapshotted with.
     */
    public function save(PropertySetting $setting, array $state): PropertySetting
    {
        return DB::transaction(function () use ($setting, $state): PropertySetting {
            $previousPreset = $setting->move_in_preset;
            $setting->update($state);

            $preset = $state['move_in_preset'] ?? null;

            if ($preset && $preset !== 'custom' && $preset !== $previousPreset) {
                app(MoveInRuleService::class)->configurePreset(
                    $setting->property_id,
                    $preset,
                    $state['currency'] ?? $setting->currency,
                );
            }

            return $setting;
        });
    }

    /**
     * Fetch today's USD→KHR rate from the NBC and save it on the property.
     * Throws whatever the fetch throws; nothing is saved then.
     *
     * @return array{usd_khr_exchange_rate: string, exchange_rate_date: string, exchange_rate_source: string, exchange_rate_fetched_at: Carbon, rate: float}
     */
    public function fetchExchangeRate(PropertySetting $setting): array
    {
        $exchangeRate = app(ExchangeRateService::class)->fetchUsdToKhr();

        $payload = [
            'usd_khr_exchange_rate' => number_format((float) $exchangeRate['rate'], 4, '.', ''),
            'exchange_rate_date' => $exchangeRate['date'],
            'exchange_rate_source' => $exchangeRate['source'],
            'exchange_rate_fetched_at' => now(),
        ];

        $setting->update($payload);
        $setting->refresh();

        return $payload + ['rate' => (float) $exchangeRate['rate']];
    }

    /** @return array<string, string> */
    public static function currencyOptions(): array
    {
        return [
            'USD' => __('USD - US Dollar ($)'),
            'KHR' => __('KHR - Khmer Riel (៛)'),
        ];
    }

    /** @return array<string, string> */
    public static function moveInPresetOptions(): array
    {
        return [
            'flexible' => __('Flexible'),
            'first_last' => __('First and last month'),
            'first_deposit' => __('First month and deposit'),
            'first_last_deposit' => __('First, last, and deposit'),
            'custom' => __('Custom'),
        ];
    }

    /** @return array<int, string> */
    public static function depositMonthOptions(): array
    {
        return [
            0 => __('No deposit'),
            1 => __('1 month (1× rent)'),
            2 => __('2 months (2× rent)'),
            3 => __('3 months (3× rent)'),
        ];
    }
}
