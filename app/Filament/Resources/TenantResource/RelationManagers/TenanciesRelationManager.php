<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Enums\RentalStatus;
use App\Filament\Resources\RentalResource;
use App\Filament\Tables\RowActionGroup;
use App\Models\Rental;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every room this person has rented from this landlord, newest first — the
 * answer to "has he lived here before?" and "when did he move out of 101?".
 *
 * Read-only on purpose: a tenancy is edited on the tenancy itself, where the
 * unit-occupancy and move-in rules live. The rows come through the Rental model,
 * so LandlordScope still hides a tenancy the same person holds with a *different*
 * landlord — the directory never leaks across the boundary.
 */
class TenanciesRelationManager extends RelationManager
{
    protected static string $relationship = 'rentalsAsTenant';

    protected static ?string $recordTitleAttribute = 'occupant_name';

    protected static ?string $icon = 'heroicon-o-key';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Tenancy history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Room'))
                    ->weight('bold')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('property.name')
                    ->label(__('Property'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('monthly_rent')
                    ->label(__('Monthly rent'))
                    ->formatStateUsing(fn ($state, Rental $record): string => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('Moved in'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('Moved out'))
                    ->date()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(RentalStatus::class),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\Action::make('open')
                        ->label(__('Open tenancy'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->url(fn (Rental $record): string => RentalResource::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading(__('No tenancy on record'));
    }
}
