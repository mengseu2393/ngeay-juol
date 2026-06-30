<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\UtilityWaiverResource\Pages;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityWaiver;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Utility waivers as a property-scoped sidebar resource (mirrors the
 * WaiversRelationManager on PropertyResource). A waiver marks a property's
 * utility as not charged — property-wide, or narrowed to a unit/rental.
 * Consumed during billing via {@see UtilityWaiver::isWaivedFor()}.
 */
class UtilityWaiverResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = UtilityWaiver::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 6;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Utilities';
    }

    public static function getNavigationLabel(): string
    {
        return __('Waivers');
    }

    public static function getModelLabel(): string
    {
        return __('Waiver');
    }

    /** Property the form options scope to: active context, else the chosen field. */
    protected static function scopedPropertyId(Forms\Get $get): ?int
    {
        return ActiveProperty::id() ?? ($get('property_id') ? (int) $get('property_id') : null);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('created_by_id')->default(fn () => auth()->id()),

            Forms\Components\Select::make('property_id')
                ->relationship('property', 'name')
                ->default(fn () => ActiveProperty::id())
                ->hidden(fn () => ActiveProperty::id() !== null)
                ->dehydrated()
                ->searchable()->preload()->live()
                ->required(fn () => ActiveProperty::id() === null),

            Forms\Components\Select::make('property_utility_id')
                ->label('Utility')
                ->options(fn (Forms\Get $get) => PropertyUtility::query()
                    ->when(static::scopedPropertyId($get), fn ($q, $pid) => $q->where('property_id', $pid))
                    ->pluck('name', 'id'))
                ->searchable()->required(),

            Forms\Components\Toggle::make('waived')->default(true)
                ->helperText('On = this utility is not charged for the scope below.'),

            Forms\Components\Select::make('unit_id')
                ->label('Limit to unit (optional)')
                ->options(fn (Forms\Get $get) => Unit::query()
                    ->when(static::scopedPropertyId($get), fn ($q, $pid) => $q->where('property_id', $pid))
                    ->pluck('room_number', 'id'))
                ->searchable()->placeholder('Whole property'),

            Forms\Components\Select::make('rental_id')
                ->label('Limit to rental (optional)')
                ->options(fn (Forms\Get $get) => Rental::query()
                    ->when(static::scopedPropertyId($get), fn ($q, $pid) => $q->where('property_id', $pid))
                    ->with('unit')->get()
                    ->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.($r->unit?->room_number ?? '')]))
                ->searchable()->placeholder('Whole property'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('propertyUtility.name')->label('Utility')->searchable(),
                Tables\Columns\TextColumn::make('unit.room_number')->label('Unit')->placeholder('Whole property'),
                Tables\Columns\TextColumn::make('rental.id')->label('Rental')->placeholder('—'),
                Tables\Columns\IconColumn::make('waived')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('waived'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUtilityWaivers::route('/'),
            'create' => Pages\CreateUtilityWaiver::route('/create'),
            'edit' => Pages\EditUtilityWaiver::route('/{record}/edit'),
        ];
    }
}
