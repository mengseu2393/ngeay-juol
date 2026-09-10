<?php

namespace App\Filament\Resources\PropertyResource\RelationManagers;

use App\Filament\Tables\RowActionGroup;
use App\Models\Rental;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Waivers for THIS property's utilities — property-wide, or narrowed to a unit/rental. */
class WaiversRelationManager extends RelationManager
{
    protected static string $relationship = 'utilityWaivers';

    protected static ?string $title = 'Waivers';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('created_by_id')->default(fn () => auth()->id()),
            Forms\Components\Select::make('property_utility_id')
                ->label(__('Utility'))
                ->options(fn () => $this->getOwnerRecord()->propertyUtilities()->pluck('name', 'id'))
                ->searchable()->required(),
            Forms\Components\Toggle::make('waived')->default(true)
                ->helperText(__('On = this utility is not charged for the scope below.')),
            Forms\Components\Select::make('unit_id')
                ->label(__('Limit to unit (optional)'))
                ->options(fn () => $this->getOwnerRecord()->units()->pluck('room_number', 'id'))
                ->searchable()->placeholder(__('Whole property')),
            Forms\Components\Select::make('rental_id')
                ->label(__('Limit to rental (optional)'))
                ->options(fn () => Rental::whereHas('unit', fn ($q) => $q->where('property_id', $this->getOwnerRecord()->id))
                    ->with('unit')->get()
                    ->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.($r->unit?->room_number ?? '')]))
                ->searchable()->placeholder(__('Whole property')),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('propertyUtility.name')->label(__('Utility')),
                Tables\Columns\TextColumn::make('unit.room_number')->label(__('Unit'))->placeholder(__('Whole property')),
                Tables\Columns\TextColumn::make('rental.id')->label(__('Rental'))->placeholder('—'),
                Tables\Columns\IconColumn::make('waived')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }
}
