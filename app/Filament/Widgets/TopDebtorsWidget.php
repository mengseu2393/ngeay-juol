<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RentalResource;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Filament\Widgets\Concerns\OrdersByPrecomputedRank;
use App\Models\Invoice;
use App\Models\Rental;
use App\Providers\Filament\LandlordPanelProvider;
use App\Support\Receivables;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Who owes money, biggest first — the people, not the paperwork.
 *
 * {@see OverdueInvoicesWidget} lists overdue *documents*; a tenant three months
 * behind appears there as three unrelated rows. Chasing happens by phone, one
 * person at a time, so this rolls every open invoice up to the tenancy and puts
 * a dialable number on each row.
 *
 * Balances are rolled up in PHP through {@see Receivables} rather than summed in
 * SQL. Invoices predating the multi-currency columns carry NULL total_usd and
 * fall back to amount_due in their property's own currency — a SUM() would score
 * those tenancies at zero and quietly drop the oldest debtors off the list.
 */
class TopDebtorsWidget extends BaseWidget
{
    use HasActivePropertyScope;
    use OrdersByPrecomputedRank;

    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    private const LIMIT = 8;

    /** @var array<int, array{usd: float, khr: float, count: int, oldest_due: ?Carbon}>|null */
    private ?array $debts = null;

    public function getHeading(): string
    {
        return __('Who to chase');
    }

    public function table(Table $table): Table
    {
        $debts = $this->debts();

        $query = Rental::query()
            ->whereIn('id', array_keys($debts) ?: [0])
            ->with(['unit', 'tenant']);

        // Ordering lives in the rollup, not in SQL; replay it over the rows.
        $this->orderByRank($query, array_keys($debts));

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Room'))
                    ->weight('bold')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('occupant_name')
                    ->label(__('Tenant'))
                    ->description(fn (Rental $record): ?string => $record->tenant?->name)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('open_invoices')
                    ->label(__('Unpaid'))
                    ->getStateUsing(fn (Rental $record): string => trans_choice(
                        '{1} :count invoice|[2,*] :count invoices',
                        $debts[$record->getKey()]['count'] ?? 0,
                        ['count' => $debts[$record->getKey()]['count'] ?? 0],
                    ))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('oldest_due')
                    ->label(__('Oldest due'))
                    ->getStateUsing(function (Rental $record) use ($debts): string {
                        $days = self::daysLate($debts[$record->getKey()]['oldest_due'] ?? null);

                        return match (true) {
                            $days === null => '—',
                            $days > 0 => __(':count days late', ['count' => $days]),
                            default => __('not due yet'),
                        };
                    })
                    ->badge()
                    ->color(function (Rental $record) use ($debts): string {
                        $days = self::daysLate($debts[$record->getKey()]['oldest_due'] ?? null);

                        return match (true) {
                            $days === null => 'gray',
                            $days > 60 => 'danger',
                            $days > 0 => 'warning',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('balance')
                    ->label(__('Balance'))
                    ->getStateUsing(fn (Rental $record): string => Receivables::format(
                        $debts[$record->getKey()] ?? ['usd' => 0.0, 'khr' => 0.0],
                    ))
                    ->weight('bold')
                    ->color('danger')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->placeholder('—')
                    ->icon('heroicon-m-phone')
                    ->getStateUsing(fn (Rental $record): ?string => self::phoneOf($record))
                    ->url(fn (Rental $record): ?string => self::telLink($record))
                    ->color(fn (Rental $record): string => self::telLink($record) !== null ? 'primary' : 'gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Nobody owes you anything'))
            ->emptyStateDescription(__('Every invoice in this view is settled.'))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Rental $record): string => RentalResource::getUrl(
                        'view',
                        ['record' => $record],
                        panel: LandlordPanelProvider::ID,
                    )),
            ]);
    }

    /**
     * Open balance per tenancy, largest first, capped at {@see self::LIMIT}.
     *
     * @return array<int, array{usd: float, khr: float, count: int, oldest_due: ?Carbon}>
     */
    private function debts(): array
    {
        if ($this->debts !== null) {
            return $this->debts;
        }

        $byRental = Receivables::openInvoices($this->activePropertyId())
            ->whereNotNull('rental_id')
            ->get()
            ->groupBy('rental_id');

        $debts = [];
        foreach ($byRental as $rentalId => $invoices) {
            $usd = 0.0;
            $khr = 0.0;
            $oldestDue = null;

            foreach ($invoices as $invoice) {
                $usd += $invoice->balance_usd;
                $khr += $invoice->balance_khr;

                if (Invoice::isPlausibleDate($invoice->due_date)
                    && ($oldestDue === null || $invoice->due_date->lt($oldestDue))) {
                    $oldestDue = $invoice->due_date;
                }
            }

            if ($usd <= 0.0 && $khr <= 0.0) {
                continue; // status says unpaid but the ledger disagrees
            }

            $debts[(int) $rentalId] = [
                'usd' => round($usd, 2),
                'khr' => round($khr, 0),
                'count' => $invoices->count(),
                'oldest_due' => $oldestDue,
            ];
        }

        uasort($debts, fn (array $a, array $b) => $b['usd'] <=> $a['usd'] ?: $b['khr'] <=> $a['khr']);

        return $this->debts = array_slice($debts, 0, self::LIMIT, true);
    }

    private static function daysLate(?Carbon $dueDate): ?int
    {
        if ($dueDate === null) {
            return null;
        }

        return (int) $dueDate->copy()->startOfDay()->diffInDays(now()->startOfDay(), false);
    }

    private static function phoneOf(Rental $rental): ?string
    {
        return $rental->occupant_phone ?: $rental->tenant?->phone_number;
    }

    /** A tappable number: on a phone this dials, on desktop it is inert but harmless. */
    private static function telLink(Rental $rental): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) self::phoneOf($rental));

        return $digits !== '' ? 'tel:'.$digits : null;
    }
}
