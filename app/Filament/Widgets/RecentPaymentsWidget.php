<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Payment;
use App\Providers\Filament\LandlordPanelProvider;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash in, newest first.
 *
 * Payment carries no LandlordScope of its own, so this scopes through the
 * invoice relation — Invoice's global scope then applies inside the subquery and
 * one landlord can never see another's ledger.
 */
class RecentPaymentsWidget extends BaseWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 6;

    public function getHeading(): string
    {
        return __('Recent payments');
    }

    public function table(Table $table): Table
    {
        $propertyId = $this->activePropertyId();

        // Unconditional whereHas: it is what pulls Invoice's LandlordScope into the
        // subquery. The property filter rides along when one is selected.
        $query = Payment::query()
            ->whereHas('invoice', fn (Builder $q) => $propertyId === null
                ? $q
                : $q->where('property_id', $propertyId))
            ->with(['invoice.rental.unit'])
            ->latest('paid_at')
            ->limit(8);

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')
                    ->label(__('When'))
                    ->since()
                    ->tooltip(fn (Payment $record): ?string => $record->paid_at?->format('d M Y H:i')),

                Tables\Columns\TextColumn::make('invoice.rental.unit.room_number')
                    ->label(__('Room'))
                    ->weight('bold')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoice.invoice_number')
                    ->label(__('Invoice'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('method')
                    ->label(__('Method'))
                    ->badge(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('Amount'))
                    // The native amount as it was taken — not the converted twin.
                    ->formatStateUsing(fn ($state, Payment $record): string => Money::format($state, $record->currency))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No payments yet'))
            ->emptyStateDescription(__('Recorded payments will appear here.'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('Invoice'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Payment $record): bool => $record->invoice !== null)
                    ->url(fn (Payment $record): string => InvoiceResource::getUrl('edit', ['record' => $record->invoice], panel: LandlordPanelProvider::ID)),
            ]);
    }
}
