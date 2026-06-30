<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Models\UtilityUsage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UtilityUsageRelationManager extends RelationManager
{
    protected static string $relationship = 'utilityUsages';

    protected static ?string $title = 'Utility usage';

    protected static ?string $icon = 'heroicon-o-bolt';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'propertyUtility:id,name,unit_of_measure,rate',
            ]))
            ->defaultSort('reading_date', 'desc')
            ->defaultGroup('reading_date')
            ->columns([
                Tables\Columns\TextColumn::make('propertyUtility.name')
                    ->label('Utility')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\ColumnGroup::make('Meter reading', [
                    Tables\Columns\TextColumn::make('old_reading')
                        ->label('Previous')
                        ->alignEnd()
                        ->color('gray')
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record)),
                    Tables\Columns\TextColumn::make('new_reading')
                        ->label('Current')
                        ->alignEnd()
                        ->color('gray')
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record)),
                    Tables\Columns\TextColumn::make('amount_used')
                        ->label('Used')
                        ->alignEnd()
                        ->weight('bold')
                        ->sortable()
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record)),
                ]),
                Tables\Columns\ColumnGroup::make('Billing', [
                    Tables\Columns\TextColumn::make('amount_billed')
                        ->label('Amount')
                        ->alignEnd()
                        ->money('USD')
                        ->state(fn (UtilityUsage $record) => $record->propertyUtility?->rate
                            ? round((float) $record->amount_used * (float) $record->propertyUtility->rate, 2)
                            : null)
                        ->placeholder('—'),

                    Tables\Columns\IconColumn::make('is_waived')
                        ->label('Waived')
                        ->boolean(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('property_utility_id')
                    ->label('Utility')
                    ->relationship('propertyUtility', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('reading_type')
                    ->options(\App\Enums\ReadingType::class),
                Tables\Filters\TernaryFilter::make('is_waived'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    protected static function reading($state, UtilityUsage $record): string
    {
        if ($state === null || $state === '') {
            return '—';
        }
        $uom = $record->propertyUtility?->unit_of_measure;
        return trim(static::fmt($state).' '.($uom ?? ''));
    }

    protected static function fmt($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    }
}
