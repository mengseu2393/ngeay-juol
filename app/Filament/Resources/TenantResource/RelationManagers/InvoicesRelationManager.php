<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Tables\RowActionGroup;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * This tenant's whole ledger, across every room they have rented: what was
 * billed, what came in, what is still open. The "Payments" row action opens the
 * individual receipts behind an invoice (payments have no landlord_id of their
 * own — they are reached through their invoice, per the data-model rules).
 *
 * Read-only: invoices are created and edited from {@see InvoiceResource}.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoicesAsTenant';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Invoices & payments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            // balance_usd / balance_khr fall back to the property's own currency
            // for pre-multi-currency invoices, so keep the settings hydrated.
            ->modifyQueryUsing(fn ($query) => $query->with(['property.settings', 'rental.unit']))
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label(__('Invoice'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.unit.room_number')
                    ->label(__('Room'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('period_start')
                    ->label(__('Period'))
                    ->date('M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('Due'))
                    ->date()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('amount_due')
                    ->label(__('Billed'))
                    ->formatStateUsing(fn ($state, Invoice $record): string => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label(__('Paid'))
                    ->color('success')
                    ->formatStateUsing(fn ($state, Invoice $record): string => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('balance')
                    ->label(__('Balance'))
                    ->state(fn (Invoice $record): float => $record->balance)
                    ->formatStateUsing(fn ($state, Invoice $record): string => Money::formatForRecord($state, $record))
                    ->color(fn (Invoice $record): string => $record->balance > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('Status'))
                    ->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('Status'))
                    ->options(InvoiceStatus::class),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\Action::make('payments')
                        ->label(__('Payments'))
                        ->icon('heroicon-o-receipt-percent')
                        ->color('gray')
                        ->modalHeading(fn (Invoice $record): string => __('Payments for :invoice', ['invoice' => $record->invoice_number]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->infolist(fn ($infolist, Invoice $record) => $infolist
                            ->record($record)
                            ->schema([
                                RepeatableEntry::make('payments')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextEntry::make('paid_at')->dateTime(),
                                        TextEntry::make('amount')
                                            ->formatStateUsing(fn ($state, Payment $record): string => Money::formatForRecord($state, $record)),
                                        TextEntry::make('method')->badge(),
                                        TextEntry::make('recordedBy.name')->label(__('Recorded by'))->placeholder('—'),
                                        TextEntry::make('receipt_number')->placeholder('—'),
                                    ])->columns(5),
                            ])),
                    Tables\Actions\Action::make('open')
                        ->label(__('Open invoice'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->url(fn (Invoice $record): string => InvoiceResource::getUrl('edit', ['record' => $record])),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('No invoices yet'));
    }
}
