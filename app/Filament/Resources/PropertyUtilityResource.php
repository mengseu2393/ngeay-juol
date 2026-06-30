<?php

namespace App\Filament\Resources;

use App\Enums\BillingType;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\PropertyUtilityResource\Pages;
use App\Models\PropertyUtility;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The per-property utility *catalog* (Electricity, Water, … with their rates and
 * billing rules) — what ManageUtilities used to manage inside the property
 * workspace. Distinct from UtilityUsageResource, which holds metered readings.
 */
class PropertyUtilityResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = PropertyUtility::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = 5;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Utilities';
    }

    public static function getNavigationLabel(): string
    {
        return __('Utilities');
    }

    public static function getModelLabel(): string
    {
        return __('Utility');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('property_id')
                ->relationship('property', 'name')
                ->default(fn () => ActiveProperty::id())
                ->hidden(fn () => ActiveProperty::id() !== null)
                ->dehydrated()
                ->searchable()->preload()
                ->required(fn () => ActiveProperty::id() === null),
            Forms\Components\TextInput::make('name')
                ->required()
                ->datalist(['Electricity', 'Water', 'Gas', 'Internet', 'Trash', 'Cleaning', 'Parking'])
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $uom = match (strtolower((string) $state)) {
                        'electricity' => 'kWh',
                        'water', 'gas' => 'm³',
                        'internet', 'trash', 'cleaning', 'parking' => 'month',
                        default => null,
                    };
                    if ($uom) {
                        $set('unit_of_measure', $uom);
                    }
                }),
            Forms\Components\TextInput::make('unit_of_measure')->required()->default('unit'),
            Forms\Components\Select::make('billing_type')->options(BillingType::class)->default(BillingType::Metered)->required(),
            Forms\Components\TextInput::make('rate')->numeric()->prefix('$')->step(0.0001)->required()
                ->helperText('Per unit (metered) or fixed amount (flat).'),
            Forms\Components\TextInput::make('provider')->placeholder('e.g. EDC, PPWSA'),
            Forms\Components\TextInput::make('account_ref')->label('Account #'),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('billing_type')->badge(),
                Tables\Columns\TextColumn::make('rate')->money('USD'),
                Tables\Columns\TextColumn::make('unit_of_measure')->label('Unit'),
                Tables\Columns\TextColumn::make('provider')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPropertyUtilities::route('/'),
            'create' => Pages\CreatePropertyUtility::route('/create'),
            'edit' => Pages\EditPropertyUtility::route('/{record}/edit'),
        ];
    }
}
