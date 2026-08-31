<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Money the landlord is still owed, in both currencies.
 *
 * Balances are read through {@see Invoice::getBalanceUsdAttribute()} rather than
 * summed in SQL: legacy invoices have NULL total_usd/paid_usd and fall back to
 * amount_due at the property's own reporting currency, which is per-property
 * logic SQL can't express. The open set is small (unpaid invoices only) and the
 * property relation is eager-loaded, so this stays one query plus the fallback.
 */
final class Receivables
{
    /** Statuses that still expect money. Draft/Paid/Cancelled are excluded. */
    public const OPEN_STATUSES = [
        InvoiceStatus::Pending,
        InvoiceStatus::Partial,
        InvoiceStatus::Overdue,
    ];

    /** Unpaid invoices, LandlordScope-scoped, optionally narrowed to one property. */
    public static function openInvoices(?int $propertyId = null): Builder
    {
        $query = Invoice::query()
            ->whereIn('payment_status', array_map(fn (InvoiceStatus $s) => $s->value, self::OPEN_STATUSES))
            // balance_usd/balance_khr read property.settings on the legacy path.
            ->with('property.settings');

        if ($propertyId !== null) {
            $query->where('property_id', $propertyId);
        }

        return $query;
    }

    /**
     * Total outstanding balance.
     *
     * @return array{usd: float, khr: float, count: int}
     */
    public static function outstanding(?int $propertyId = null): array
    {
        return self::summarise(self::openInvoices($propertyId)->get());
    }

    /**
     * Outstanding split by how long it has been overdue.
     *
     * @return array<string, array{label: string, usd: float, khr: float, count: int}>
     */
    public static function aging(?int $propertyId = null): array
    {
        $today = now()->startOfDay();

        $buckets = [
            'not_due' => __('Not due yet'),
            '1_30' => __('1–30 days'),
            '31_60' => __('31–60 days'),
            '60_plus' => __('60+ days'),
        ];

        $grouped = self::openInvoices($propertyId)->get()->groupBy(function (Invoice $invoice) use ($today): string {
            if (! Invoice::isPlausibleDate($invoice->due_date) || $invoice->due_date->gte($today)) {
                return 'not_due';
            }

            $daysLate = (int) $invoice->due_date->diffInDays($today);

            return match (true) {
                $daysLate <= 30 => '1_30',
                $daysLate <= 60 => '31_60',
                default => '60_plus',
            };
        });

        $result = [];
        foreach ($buckets as $key => $label) {
            $result[$key] = ['label' => $label] + self::summarise($grouped->get($key) ?? collect());
        }

        return $result;
    }

    /**
     * Balance in a single reporting currency — for charts, which cannot plot
     * "$120 / ៛80,000" on one axis.
     */
    public static function inCurrency(array $bucket, string $currency): float
    {
        return Money::normalize($currency) === 'KHR' ? $bucket['khr'] : $bucket['usd'];
    }

    /** "$120.00 / ៛480,000" — the project's standing way to show a mixed-currency total. */
    public static function format(array $bucket): string
    {
        return Money::format($bucket['usd'], 'USD').' / '.Money::format($bucket['khr'], 'KHR');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array{usd: float, khr: float, count: int}
     */
    private static function summarise(Collection $invoices): array
    {
        $usd = 0.0;
        $khr = 0.0;

        foreach ($invoices as $invoice) {
            $usd += $invoice->balance_usd;
            $khr += $invoice->balance_khr;
        }

        return [
            'usd' => round($usd, 2),
            'khr' => round($khr, 0),
            'count' => $invoices->count(),
        ];
    }
}
