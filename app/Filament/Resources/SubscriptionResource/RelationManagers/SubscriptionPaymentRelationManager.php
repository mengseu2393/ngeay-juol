<?php

namespace App\Filament\Resources\SubscriptionResource\RelationManagers;

use App\Enums\SubscriptionPaymentStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionPaymentRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $recordTitleAttribute = 'receipt_number';

    public static function getModelLabel(): string
    {
        return __('Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label('Receipt')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('method')
                    ->label('Method'),
                Tables\Columns\TextColumn::make('gateway')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('covers_from')
                    ->label('Period')
                    ->date()
                    ->description(fn ($record) => $record->covers_to?->format('Y-m-d')),
                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SubscriptionPaymentStatus::class),
            ])
            ->headerActions([])
            ->actions([]);
    }
}
