<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

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

/**
 * Read-only payment history for a room: every invoice ever billed to this unit
 * (across all tenancies), who the tenant was, and how much they've paid. The
 * "Payments" row action drills into the individual receipts for that invoice.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Payment history';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_number')
            ->defaultSort('issue_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')->label(__('Invoice'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')->label(__('Tenant'))->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('period_start')->label(__('Period'))->date('M Y')->sortable(),
                Tables\Columns\TextColumn::make('amount_due')
                    ->label(__('Billed'))
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label(__('Paid'))
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                    ->color('success'),
                Tables\Columns\TextColumn::make('balance')->label(__('Balance'))
                    ->state(fn (Invoice $r) => $r->balance)
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                    ->color(fn (Invoice $r) => $r->balance > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('payment_status')->label(__('Status'))->badge(),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('payments')
                        ->label(__('Payments'))
                        ->icon('heroicon-o-receipt-percent')
                        ->color('gray')
                        ->modalHeading(fn (Invoice $record) => __('Payments for :invoice', ['invoice' => $record->invoice_number]))
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
                                            ->formatStateUsing(fn ($state, Payment $record) => Money::formatForRecord($state, $record)),
                                        TextEntry::make('method')->badge(),
                                        TextEntry::make('recordedBy.name')->label(__('Recorded by'))->placeholder('—'),
                                        TextEntry::make('receipt_number')->placeholder('—'),
                                    ])->columns(5),
                            ])),
                    Tables\Actions\Action::make('open')
                        ->label(__('Open'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Invoice $record) => InvoiceResource::getUrl('edit', ['record' => $record]))
                        ->openUrlInNewTab(),
                ]),
            ]);
    }
}
