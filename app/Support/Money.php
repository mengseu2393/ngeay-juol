<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Database\Eloquent\Model;

class Money
{
    /** @var array<int, string> */
    private static array $currencyByProperty = [];

    public static function normalize(?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return in_array($currency, ['USD', 'KHR'], true) ? $currency : 'USD';
    }

    public static function symbol(?string $currency): string
    {
        return self::normalize($currency) === 'KHR' ? '៛' : '$';
    }

    public static function decimals(?string $currency): int
    {
        return self::normalize($currency) === 'KHR' ? 0 : 2;
    }

    public static function format(mixed $value, ?string $currency = null, ?int $decimals = null): string
    {
        $currency = self::normalize($currency);
        $decimals ??= self::decimals($currency);

        return self::symbol($currency).number_format((float) $value, $decimals);
    }

    /**
     * Show a USD/KHR pair in the reporting currency only.
     *
     * $twin = both figures are the same amount converted (invoice balances,
     * payments) — show just the property's currency. Otherwise they are
     * genuinely separate native-currency totals — drop whichever side is zero.
     * With no property selected (portfolio / platform view) twins keep both.
     */
    public static function formatPair(float $usd, float $khr, bool $twin = true, ?string $currency = null): string
    {
        $currency ??= ActiveProperty::id() ? self::activeCurrency() : null;

        if ($twin) {
            if ($currency === null) {
                return self::format($usd, 'USD').' / '.self::format($khr, 'KHR');
            }

            return self::format($currency === 'KHR' ? $khr : $usd, $currency);
        }

        $parts = [];
        if ($usd != 0.0) {
            $parts[] = self::format($usd, 'USD');
        }
        if ($khr != 0.0) {
            $parts[] = self::format($khr, 'KHR');
        }

        return $parts ? implode(' / ', $parts) : self::format(0, $currency ?? 'USD');
    }

    public static function activeCurrency(): string
    {
        return self::forPropertyId(ActiveProperty::id());
    }

    public static function activeSymbol(): string
    {
        return self::symbol(self::activeCurrency());
    }

    public static function activeFormat(mixed $value, ?int $decimals = null): string
    {
        return self::format($value, self::activeCurrency(), $decimals);
    }

    public static function forPropertyId(?int $propertyId): string
    {
        if (! $propertyId) {
            return 'USD';
        }

        if (! array_key_exists($propertyId, self::$currencyByProperty)) {
            self::$currencyByProperty[$propertyId] = self::normalize(
                PropertySetting::query()->where('property_id', $propertyId)->value('currency'),
            );
        }

        return self::$currencyByProperty[$propertyId];
    }

    public static function forRecord(mixed $record): string
    {
        if ($record instanceof Unit && ! empty($record->rent_currency)) {
            return self::normalize($record->rent_currency);
        }
        if ($record instanceof Rental && ! empty($record->monthly_rent_currency)) {
            return self::normalize($record->monthly_rent_currency);
        }
        if ($record instanceof PropertyUtility && ! empty($record->currency)) {
            return self::normalize($record->currency);
        }
        if ($record instanceof Payment && ! empty($record->currency)) {
            return self::normalize($record->currency);
        }
        if ($record instanceof Model) {
            if (isset($record->currency) && ! empty($record->currency)) {
                return self::normalize($record->currency);
            }
            if (isset($record->rent_currency) && ! empty($record->rent_currency)) {
                return self::normalize($record->rent_currency);
            }
            if (isset($record->monthly_rent_currency) && ! empty($record->monthly_rent_currency)) {
                return self::normalize($record->monthly_rent_currency);
            }
        }

        $propertyId = self::propertyIdFor($record);

        if ($propertyId) {
            return self::forPropertyId($propertyId);
        }

        if ($record instanceof Property && $record->relationLoaded('settings')) {
            return self::normalize($record->settings?->currency);
        }

        return self::activeCurrency();
    }

    public static function symbolForRecord(mixed $record): string
    {
        return self::symbol(self::forRecord($record));
    }

    public static function formatForRecord(mixed $value, mixed $record, ?int $decimals = null): string
    {
        return self::format($value, self::forRecord($record), $decimals);
    }

    public static function symbolForUnitId(mixed $unitId): string
    {
        return self::symbol(self::forUnitId($unitId));
    }

    public static function forUnitId(mixed $unitId): string
    {
        if (! $unitId) {
            return self::activeCurrency();
        }

        return self::forPropertyId(Unit::withoutGlobalScopes()->whereKey($unitId)->value('property_id'));
    }

    private static function propertyIdFor(mixed $record): ?int
    {
        if (! $record instanceof Model) {
            return null;
        }

        if ($record instanceof Property) {
            return $record->getKey();
        }

        if ($record instanceof Invoice) {
            return $record->property_id
                ?: $record->property?->getKey()
                ?: $record->rental?->property_id
                ?: $record->rental?->unit?->property_id;
        }

        if ($record instanceof Payment) {
            return self::propertyIdFor($record->invoice);
        }

        if ($record instanceof Rental || $record instanceof Unit || $record instanceof PropertyUtility) {
            return $record->property_id;
        }

        if ($record instanceof UtilityUsage) {
            return $record->propertyUtility?->property_id ?: $record->unit?->property_id;
        }

        if (method_exists($record, 'invoice')) {
            return self::propertyIdFor($record->invoice);
        }

        if (property_exists($record, 'property_id') || isset($record->property_id)) {
            return (int) $record->property_id ?: null;
        }

        return null;
    }

    public static function convert(mixed $amount, string $from, string $to, float $rate, ?int $decimals = null): float
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        $amount = (float) $amount;

        if ($from === $to) {
            return $amount;
        }

        if ($from === 'USD' && $to === 'KHR') {
            $decimals ??= 0;

            return round($amount * $rate, $decimals);
        }

        if ($from === 'KHR' && $to === 'USD') {
            $decimals ??= 2;

            return $rate > 0 ? round($amount / $rate, $decimals) : 0.0;
        }

        return $amount;
    }

    public static function computeLineConvertedAmounts(float $amount, string $currency, float $rate): array
    {
        $currency = self::normalize($currency);
        if ($currency === 'USD') {
            return [
                'amount_usd' => $amount,
                'amount_khr' => self::convert($amount, 'USD', 'KHR', $rate),
            ];
        } else {
            return [
                'amount_usd' => self::convert($amount, 'KHR', 'USD', $rate),
                'amount_khr' => $amount,
            ];
        }
    }

    public static function sumLineTotals(iterable $lines, string $targetCurrency, float $rate): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $currency = self::normalize($line->currency ?? 'USD');
            $amount = (float) ($line->amount ?? 0);
            $sum += self::convert($amount, $currency, $targetCurrency, $rate);
        }

        return $sum;
    }

    public static function sumPaymentTotals(iterable $payments, string $targetCurrency, float $rate): float
    {
        $sum = 0.0;
        foreach ($payments as $payment) {
            $currency = self::normalize($payment->currency ?? 'USD');
            $amount = (float) ($payment->amount ?? 0);
            $sum += self::convert($amount, $currency, $targetCurrency, $rate);
        }

        return $sum;
    }

    public static function resolveRemainingBalance(float $total, float $paid, string $currency): float
    {
        $decimals = self::decimals($currency);

        return round(max(0.0, $total - $paid), $decimals);
    }

    /**
     * Format invoice amounts into a compact multi-currency format if snapshot is available,
     * or fallback to single-currency formatting for legacy invoices.
     */
    public static function formatInvoiceAmount(Invoice $invoice, string $type): string
    {
        $rate = (float) $invoice->usd_khr_rate;

        if ($rate <= 0) {
            $amount = match ($type) {
                'due' => $invoice->amount_due,
                'paid' => $invoice->amount_paid,
                'balance' => $invoice->balance,
            };

            return self::formatForRecord($amount, $invoice);
        }

        $usd = 0.0;
        $khr = 0.0;

        switch ($type) {
            case 'due':
                $usd = (float) ($invoice->total_usd ?? $invoice->amount_due);
                $khr = (float) ($invoice->total_khr ?? ($invoice->amount_due * $rate));
                break;
            case 'paid':
                $usd = (float) ($invoice->paid_usd ?? $invoice->amount_paid);
                $khr = (float) ($invoice->paid_khr ?? ($invoice->amount_paid * $rate));
                break;
            case 'balance':
                $usd = $invoice->balance_usd;
                $khr = $invoice->balance_khr;
                break;
        }

        $usdFormatted = self::format($usd, 'USD');
        $khrFormatted = self::format($khr, 'KHR');

        return "{$usdFormatted} / {$khrFormatted}";
    }
}
