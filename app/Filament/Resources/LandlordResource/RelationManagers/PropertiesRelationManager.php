<?php

namespace App\Filament\Resources\LandlordResource\RelationManagers;

use App\Enums\PropertyType;
use App\Filament\Resources\PropertyResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every property owned by the selected landlord. */
class PropertiesRelationManager extends RelationManager
{
    protected static string $relationship = 'properties';

    protected static ?string $title = 'Properties';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Relation managers are read-only by default on a ViewRecord page (which
     * ViewLandlord is), hiding the create/edit actions. Opt back in so admins
     * can create properties for a landlord from here.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        // Reuse the canonical property form. The landlord_id picker hides itself
        // here — the property is associated with this landlord via the
        // `properties` relationship, so admins can't mis-assign it.
        return PropertyResource::form($form);
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                // Lets an admin stand up a property for a landlord who has none.
                Tables\Actions\CreateAction::make()
                    ->label(__('New Property'))
                    ->mutateFormDataUsing(function (array $data): array {
                        // Belt-and-braces: pin the property to this landlord
                        // regardless of what the (hidden) picker contains.
                        $data['landlord_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('property_type')->badge(),
                Tables\Columns\TextColumn::make('city')->label('Province / City')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('units_count')->counts('units')->label('Units')->badge(),
                Tables\Columns\TextColumn::make('rentals_count')->counts('rentals')->label('Tenancies')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('invoices_count')->counts('invoices')->label('Invoices')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('property_type')->options(PropertyType::class),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    // PropertyResource lives in the separate `landlord` panel. Select
                    // the property into the active-property context first so the
                    // landlord workspace (room generation, scoped resources, …) opens
                    // in context, then redirect into that panel's property view.
                    ->action(function ($record) {
                        \App\Support\ActiveProperty::set($record->getKey());

                        return redirect(PropertyResource::getUrl('view', ['record' => $record], panel: 'landlord'));
                    }),
            ]);
    }
}
