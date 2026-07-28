<?php

namespace App\Filament\Resources;

use App\Enums\RentalStatus;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\RentalResource\Pages;
use App\Models\Rental;
use App\Services\TenancyService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RentalResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = Rental::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 3;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Tenancy';
    }

    public static function getNavigationLabel(): string
    {
        return __('Tenants');
    }

    public static function getModelLabel(): string
    {
        return __('Rental');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Tenancy'))
                ->schema([
                    Forms\Components\Select::make('unit_id')
                        ->relationship(
                            'unit',
                            'room_number',
                            // Only suggest vacant rooms (no active tenancy). On edit, keep
                            // this rental's own room selectable so it isn't dropped.
                            fn ($query, ?Rental $record) => $query
                                ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
                                ->where(fn ($q) => $q->whereDoesntHave('activeRental')
                                    ->when($record?->unit_id, fn ($qq, $uid) => $qq->orWhere('id', $uid))),
                        )
                        ->helperText(__('Only vacant rooms are shown.'))
                        ->searchable()->preload()->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state && $unit = \App\Models\Unit::find($state)) {
                                $set('monthly_rent', $unit->rent_amount);
                                $set('monthly_rent_currency', $unit->rent_currency ?: 'USD');
                                $set('security_deposit_currency', $unit->rent_currency ?: 'USD');
                            }
                        })
                        // Guard "one active tenancy per unit" with a friendly message
                        // before the DB unique index throws a raw constraint error.
                        // Excludes the current record on edit; only checks Active rentals.
                        ->rule(function (Forms\Get $get, ?Rental $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                if (! $value) {
                                    return;
                                }

                                $status = $get('status') ?? RentalStatus::Active;
                                $statusValue = $status instanceof RentalStatus ? $status->value : (int) $status;
                                if ($statusValue !== RentalStatus::Active->value) {
                                    return; // only an Active tenancy occupies the unit
                                }

                                // The DB allows only one Active tenancy per unit (regardless of
                                // dates), so guard on active-existence — not date overlap.
                                if (TenancyService::hasActiveTenancy((int) $value, $record?->getKey())) {
                                    $fail(__('This unit already has an active tenancy. End or vacate it before starting a new one.'));
                                }
                            };
                        }),
                    Forms\Components\Select::make('status')->options(RentalStatus::class)->default(RentalStatus::Active)->required(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('monthly_rent')
                                ->label(__('Monthly rent'))
                                ->numeric()
                                ->required(),
                            Forms\Components\Select::make('monthly_rent_currency')
                                ->label(__('Rent currency'))
                                ->options([
                                    'USD' => 'USD ($)',
                                    'KHR' => 'KHR (៛)',
                                ])
                                ->default(fn () => ActiveProperty::id() ? Money::forPropertyId(ActiveProperty::id()) : 'USD')
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    $set('security_deposit_currency', $state);
                                })
                                ->required(),
                        ]),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('security_deposit')
                                ->label(__('Security deposit'))
                                ->numeric()
                                ->default(0)
                                ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? 0 : $state),
                            Forms\Components\Select::make('security_deposit_currency')
                                ->label(__('Deposit currency'))
                                ->options([
                                    'USD' => 'USD ($)',
                                    'KHR' => 'KHR (៛)',
                                ])
                                ->default(fn () => ActiveProperty::id() ? Money::forPropertyId(ActiveProperty::id()) : 'USD')
                                ->required(),
                        ]),
                    Forms\Components\DatePicker::make('start_date')->required(),
                    Forms\Components\DatePicker::make('end_date'),
                ])->columns(2),

            Forms\Components\Section::make(__('Tenant / Occupant'))
                ->description(__('Primary occupant details. Additional co-tenants or dependents can be managed from the "Occupants" tab after saving.'))
                ->schema([
                    Forms\Components\TextInput::make('occupant_name')->label(__('Full name'))->required(),
                    Forms\Components\TextInput::make('occupant_phone')->label(__('Phone'))->tel(),
                    Forms\Components\Select::make('occupant_gender')
                        ->label(__('Gender'))
                        ->options([
                            'male' => __('Male'),
                            'female' => __('Female'),
                            'other' => __('Other'),
                        ])
                        ->placeholder(__('Select gender')),
                    Forms\Components\DatePicker::make('occupant_dob')
                        ->label(__('Date of birth'))
                        ->maxDate(now()),
                    Forms\Components\TextInput::make('occupant_nationality')->label(__('Nationality'))
                        ->placeholder(__('e.g. Khmer, Vietnamese')),
                    Forms\Components\TextInput::make('occupant_workplace')->label(__('Workplace'))
                        ->placeholder(__('e.g. company name')),
                    Forms\Components\TextInput::make('occupant_id_card')->label(__('ID card number')),
                    Forms\Components\TextInput::make('occupant_address')->label(__('Address')),
                    
                    Forms\Components\Fieldset::make(__('Emergency contact'))
                        ->schema([
                            Forms\Components\TextInput::make('emergency_contact_name')->label(__('Name')),
                            Forms\Components\TextInput::make('emergency_contact_phone')->label(__('Phone'))->tel(),
                            Forms\Components\TextInput::make('emergency_contact_relationship')
                                ->label(__('Relationship'))
                                ->placeholder(__('e.g. mother, brother')),
                        ])->columns(3),

                    Forms\Components\Fieldset::make(__('Guarantor'))
                        ->schema([
                            Forms\Components\TextInput::make('guarantor_name')->label(__('Name')),
                            Forms\Components\TextInput::make('guarantor_phone')->label(__('Phone'))->tel(),
                            Forms\Components\TextInput::make('guarantor_id_number')->label(__('ID number')),
                            Forms\Components\TextInput::make('guarantor_address')->label(__('Address')),
                        ])->columns(2),

                    SpatieMediaLibraryFileUpload::make('id_cards')
                        ->collection('id_cards')
                        ->label(__('ID card photos'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(4)
                        ->helperText(__('Front/back of national ID, passport, etc.'))
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make(__('Agreement'))
                ->schema([
                    Forms\Components\TextInput::make('lease_agreement')->label(__('Agreement reference / file path')),
                    Forms\Components\DateTimePicker::make('signed_at'),
                    Forms\Components\Textarea::make('terms_conditions')->columnSpanFull(),
                    Forms\Components\Textarea::make('notes')
                        ->label(__('Notes'))
                        ->placeholder(__('Private notes about this tenancy'))
                        ->columnSpanFull(),
                ])->columns(2)
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')->label(__('Unit'))->sortable(),
                Tables\Columns\TextColumn::make('occupant_name')->label(__('Occupant'))->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('occupants_count')
                    ->label(__('Occupants'))
                    ->counts('occupants')
                    ->badge()
                    ->color(fn ($state) => $state > 1 ? 'info' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('occupant_id_card')->label(__('ID card'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tenant.username')->label(__('Login'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('monthly_rent')
                    ->formatStateUsing(fn ($state, Rental $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('start_date')->date(),
                Tables\Columns\TextColumn::make('end_date')->date(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(RentalStatus::class),
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

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\RentalResource\RelationManagers\OccupantsRelationManager::class,
            \App\Filament\Resources\UnitResource\RelationManagers\UtilityUsageRelationManager::class,
            \App\Filament\Resources\PropertyUtilityResource\RelationManagers\ChargeRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentals::route('/'),
            'create' => Pages\CreateRental::route('/create'),
            'view' => Pages\ViewRental::route('/{record}'),
            'edit' => Pages\EditRental::route('/{record}/edit'),
        ];
    }
}
