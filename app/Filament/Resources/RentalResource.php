<?php

namespace App\Filament\Resources;

use App\Enums\RentalStatus;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\RentalResource\Pages;
use App\Models\Rental;
use App\Services\TenancyService;
use App\Support\ActiveProperty;
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
            Forms\Components\Section::make('Tenancy')
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
                        // selecting a unit pulls its rent (the tenant login is minted
                        // automatically on save — one login per tenant)
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state && $unit = \App\Models\Unit::find($state)) {
                                $set('monthly_rent', $unit->rent_amount);
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
                    Forms\Components\TextInput::make('monthly_rent')->numeric()->prefix('$')->required(),
                    Forms\Components\TextInput::make('security_deposit')->numeric()->prefix('$')
                        ->default(0)
                        ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? 0 : $state),
                    Forms\Components\DatePicker::make('start_date')->required(),
                    Forms\Components\DatePicker::make('end_date'),
                ])->columns(2),

            Forms\Components\Section::make('Tenant / Occupant')
                ->description('The actual person renting this period (e.g. C1, then C2 next year). Kept per tenancy so history is preserved. Their login is created automatically.')
                ->schema([
                    Forms\Components\TextInput::make('occupant_name')->label('Full name')->required(),
                    Forms\Components\TextInput::make('occupant_phone')->label('Phone')->tel(),
                    Forms\Components\TextInput::make('occupant_id_card')->label('ID card number'),
                    Forms\Components\TextInput::make('occupant_address')->label('Address'),
                    SpatieMediaLibraryFileUpload::make('id_cards')
                        ->collection('id_cards')
                        ->label('ID card photos')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(4)
                        ->helperText('Front/back of national ID, passport, etc.')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('Agreement')
                ->schema([
                    Forms\Components\TextInput::make('lease_agreement')->label('Agreement reference / file path'),
                    Forms\Components\DateTimePicker::make('signed_at'),
                    Forms\Components\Textarea::make('terms_conditions')->columnSpanFull(),
                ])->columns(2)
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')->label('Unit')->sortable(),
                Tables\Columns\TextColumn::make('occupant_name')->label('Occupant')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('occupant_id_card')->label('ID card')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tenant.username')->label('Login')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('monthly_rent')->money('USD'),
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
            \App\Filament\Resources\UnitResource\RelationManagers\UtilityUsageRelationManager::class,
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
