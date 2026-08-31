<?php

namespace App\Filament\Widgets;

use App\Enums\RentalStatus;
use App\Filament\Resources\RentalResource;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Rental;
use App\Providers\Filament\LandlordPanelProvider;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Tenancies ending in the next 60 days.
 *
 * Every one of them is either a renewal conversation or a room to re-let plus a
 * deposit to refund — all of which need starting before the end date, not after.
 */
class LeaseExpiryWidget extends BaseWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 5;

    private const HORIZON_DAYS = 60;

    public function getHeading(): string
    {
        return __('Leases ending soon');
    }

    public function table(Table $table): Table
    {
        $query = $this->scopeToActiveProperty(Rental::query())
            ->with(['unit', 'tenant'])
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(self::HORIZON_DAYS)->endOfDay()])
            ->orderBy('end_date')
            ->limit(8);

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

                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('Ends'))
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('days_left')
                    ->label(__('In'))
                    ->getStateUsing(function (Rental $record): string {
                        $days = (int) now()->startOfDay()->diffInDays($record->end_date->startOfDay(), false);

                        return $days <= 0
                            ? __('today')
                            : trans_choice('{1} :count day|[2,*] :count days', $days, ['count' => $days]);
                    })
                    ->badge()
                    ->color(function (Rental $record): string {
                        $days = (int) now()->startOfDay()->diffInDays($record->end_date->startOfDay(), false);

                        return match (true) {
                            $days <= 7 => 'danger',
                            $days <= 30 => 'warning',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('security_deposit')
                    ->label(__('Deposit to refund'))
                    ->formatStateUsing(fn ($state, Rental $record): string => $state === null
                        ? '—'
                        : Money::format($state, $record->security_deposit_currency ?: Money::forRecord($record)))
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No leases ending soon'))
            ->emptyStateDescription(__('Nothing expires in the next :days days.', ['days' => self::HORIZON_DAYS]))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Rental $record): string => RentalResource::getUrl('view', ['record' => $record], panel: LandlordPanelProvider::ID)),
            ]);
    }
}
