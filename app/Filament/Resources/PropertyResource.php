<?php

namespace App\Filament\Resources;

use App\Enums\PropertyType;
use App\Filament\Resources\PropertyResource\Pages;
use App\Filament\Resources\PropertyResource\RelationManagers;
use App\Models\Property;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Portfolio';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Portfolio';
    }

    public static function getNavigationLabel(): string
    {
        return __('All properties');
    }

    public static function getModelLabel(): string
    {
        return __('Property');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Property'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->relationship('landlord', 'name')
                        ->searchable()
                        ->preload()
                        // Hidden inside a relation manager: the owning landlord is
                        // already fixed by the relationship there.
                        ->visible(fn ($livewire) => auth()->user()?->isPlatformStaff()
                            && ! $livewire instanceof \Filament\Resources\RelationManagers\RelationManager)
                        ->required(fn ($livewire) => auth()->user()?->isPlatformStaff()
                            && ! $livewire instanceof \Filament\Resources\RelationManagers\RelationManager),
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\Select::make('property_type')
                        ->options(PropertyType::class)
                        ->default(PropertyType::Apartment)
                        ->required(),
                    Forms\Components\Textarea::make('description')->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make(__('Address'))
                ->schema([
                    Forms\Components\TextInput::make('address_line'),
                    Forms\Components\TextInput::make('street'),
                    Forms\Components\TextInput::make('village')->label('Village (Phum)'),
                    Forms\Components\TextInput::make('commune')->label('Commune (Sangkat)'),
                    Forms\Components\TextInput::make('district')->label('District (Khan)'),
                    Forms\Components\TextInput::make('city')->label('Province / City'),
                    Forms\Components\TextInput::make('postal_code'),
                ])->columns(2),

            Forms\Components\TagsInput::make('amenities')->columnSpanFull(),

            // Billing & lease settings moved to the dedicated, sidebar-level
            // PropertySettings page (App\Filament\Pages\PropertySettings), scoped
            // to the active property selected in the sidebar switcher.
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('landlord.name')
                    ->label('Landlord')
                    ->visible(fn () => auth()->user()?->isPlatformStaff())
                    ->searchable(),
                Tables\Columns\TextColumn::make('property_type')->badge(),
                Tables\Columns\TextColumn::make('city')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('units_count')->counts('units')->label('Units')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('property_type')->options(PropertyType::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** No relation panels on the property edit page. */
    public static function getRelations(): array
    {
        return [];
    }

    // The per-property top-tab "workspace" (ManageRooms/Tenants/Invoices/Utilities)
    // is retired: the sidebar now follows the selected property, so those live as
    // scoped sidebar resources. Rooms keeps its generator/login actions in
    // UnitResource; the utility catalog moved to PropertyUtilityResource.
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProperties::route('/'),
            'create' => Pages\CreateProperty::route('/create'),
            'view' => Pages\ViewProperty::route('/{record}'),
            'edit' => Pages\EditProperty::route('/{record}/edit'),
        ];
    }
}
