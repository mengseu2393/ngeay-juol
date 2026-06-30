<?php

namespace App\Filament\Resources\SubscriptionResource\RelationManagers;

use App\Enums\SubscriptionAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getModelLabel(): string
    {
        return __('History entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Subscription history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionAction $state) => $state->getLabel()),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge(),
                Tables\Columns\TextColumn::make('period_start')
                    ->label('From')
                    ->date(),
                Tables\Columns\TextColumn::make('period_end')
                    ->label('To')
                    ->date(),
                Tables\Columns\TextColumn::make('amount_charged')
                    ->money(fn ($record) => $record->subscription?->currency ?? 'USD'),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('By')
                    ->placeholder('System'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options(SubscriptionAction::class),
            ])
            ->headerActions([])
            ->actions([]);
    }
}
