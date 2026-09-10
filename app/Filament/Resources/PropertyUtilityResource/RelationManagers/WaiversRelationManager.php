<?php

namespace App\Filament\Resources\PropertyUtilityResource\RelationManagers;

use App\Filament\Tables\RowActionGroup;
use App\Models\Rental;
use App\Models\Unit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manages waivers scoped to a single PropertyUtility (the parent record on the
 * edit page). Replaces the standalone UtilityWaiverResource sidebar entry so
 * landlords manage waivers *inside* the utility they belong to.
 */
class WaiversRelationManager extends RelationManager
{
    protected static string $relationship = 'waivers';

    protected static ?string $title = 'Waivers';

    public function form(Form $form): Form
    {
        $propertyId = $this->getOwnerRecord()->property_id;

        return $form->schema([
            Forms\Components\Hidden::make('created_by_id')->default(fn () => auth()->id()),
            Forms\Components\Hidden::make('property_id')->default($propertyId),
            Forms\Components\Hidden::make('property_utility_id')
                ->default(fn () => $this->getOwnerRecord()->getKey()),

            Forms\Components\Toggle::make('waived')->default(true)
                ->label(__('Waived'))
                ->helperText(__('On = this utility is not charged for the scope below.'))
                ->columnSpanFull(),

            Forms\Components\Select::make('unit_id')
                ->label(__('Limit to unit (optional)'))
                ->options(fn () => Unit::where('property_id', $propertyId)
                    ->pluck('room_number', 'id'))
                ->searchable()->placeholder(__('Whole property')),

            Forms\Components\Select::make('rental_id')
                ->label(__('Limit to rental (optional)'))
                ->options(fn () => Rental::whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
                    ->with('unit')->get()
                    ->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.($r->unit?->room_number ?? '')]))
                ->searchable()->placeholder(__('Whole property')),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Unit'))
                    ->placeholder(__('Whole property')),
                Tables\Columns\TextColumn::make('rental.id')
                    ->label(__('Rental'))
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('waived')
                    ->label(__('Waived'))
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['property_id'] = $this->getOwnerRecord()->property_id;
                        $data['property_utility_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
