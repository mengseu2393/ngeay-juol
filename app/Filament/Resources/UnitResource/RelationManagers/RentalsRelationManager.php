<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Services\RoomAccountService;
use App\Services\TenancyService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The room's tenant timeline, managed from the unit's edit page: who rents (or
 * rented) this room and for which period. Tenancies are sequential — Tenant A
 * (Jan–May), then Tenant B (Jun–Dec) — never overlapping while Active (guarded by
 * {@see TenancyService::hasOverlap}). Each tenant gets its own login (one login
 * per tenant), auto-created from the occupant's name.
 */
class RentalsRelationManager extends RelationManager
{
    protected static string $relationship = 'rentals';

    protected static ?string $title = 'Tenants';

    protected static ?string $recordTitleAttribute = 'occupant_name';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Tenancy'))
                ->description(__('The person renting this room for this period. Their login is created automatically.'))
                ->schema([
                    Forms\Components\TextInput::make('occupant_name')->label(__('Full name'))->required()->maxLength(255),
                    Forms\Components\TextInput::make('occupant_phone')->label(__('Phone'))->tel(),
                    Forms\Components\TextInput::make('occupant_id_card')->label(__('ID card number')),
                    Forms\Components\SpatieMediaLibraryFileUpload::make('id_cards')
                        ->collection('id_cards')
                        ->label(__('ID card photos'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(4)
                        ->helperText(__('Front/back of national ID, passport, etc.'))
                        ->columnSpanFull(),
                    Forms\Components\Select::make('status')
                        ->options(RentalStatus::class)
                        ->default(RentalStatus::Active)
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('monthly_rent')
                        ->numeric()->prefix('$')->required()
                        ->default(fn () => $this->getOwnerRecord()->rent_amount),
                    Forms\Components\TextInput::make('security_deposit')
                        ->numeric()->prefix('$')->default(0)
                        ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? 0 : $state),
                    Forms\Components\DatePicker::make('start_date')
                        ->required()
                        // Guard "one active tenancy per room" before the DB unique index throws.
                        // Only Active tenancies occupy the room; excludes the record being edited.
                        ->rule(function (Forms\Get $get, ?Rental $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                if (! $value) {
                                    return;
                                }
                                $status = $get('status') ?? RentalStatus::Active;
                                $statusValue = $status instanceof RentalStatus ? $status->value : (int) $status;
                                if ($statusValue !== RentalStatus::Active->value) {
                                    return;
                                }
                                // Only one Active tenancy per room is allowed (dates irrelevant).
                                if (TenancyService::hasActiveTenancy($this->getOwnerRecord()->getKey(), $record?->getKey())) {
                                    $fail(__('This room already has an active tenant. End the current tenancy before starting a new one.'));
                                }
                            };
                        }),
                    Forms\Components\DatePicker::make('end_date')
                        ->helperText(__('Leave blank while the tenant is still renting.')),
                ])->columns(2),

            Forms\Components\Section::make(__('Agreement'))
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('occupant_address')->label(__('Address'))->columnSpanFull(),
                    Forms\Components\Textarea::make('terms_conditions')->label(__('Terms & conditions'))->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('occupant_name')
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occupant_name')->label(__('Tenant'))->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('tenant.username')->label(__('Login'))->placeholder('—')->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    // Click an active tenancy's status to end it.
                    ->action($this->endRentalAction())
                    ->tooltip(fn (Rental $record) => $record->isActive() ? __('Click to end tenancy') : null),
                Tables\Columns\TextColumn::make('start_date')->date(),
                Tables\Columns\TextColumn::make('end_date')->date()->placeholder(__('Current')),
                Tables\Columns\TextColumn::make('monthly_rent')->money('USD'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(RentalStatus::class),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Add tenant'))
                    // tenant_id is NOT NULL; issueTenantLogin() mints the per-tenant
                    // account, sets tenant_id and persists the tenancy in one step.
                    ->using(function (array $data): Rental {
                        $rental = $this->getRelationship()->make($this->mutateRentalData($data));
                        $this->issueTenantLogin($rental);

                        return $rental;
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('login')
                        ->label(__('Reset login'))
                        ->icon('heroicon-o-key')
                        ->color('gray')
                        ->modalHeading(fn (Rental $record) => __('Reset login for').' '.($record->occupant_name ?: __('tenant')))
                        ->modalDescription(fn (Rental $record) => $record->tenant
                            ? __('Username').': '.$record->tenant->username
                            : __('A login will be created for this tenant.'))
                        ->form([
                            Forms\Components\TextInput::make('password')->label(__('Password'))->password()->revealable()
                                ->helperText(__('Leave blank to auto-generate a password.')),
                        ])
                        ->action(fn (Rental $record, array $data) => $this->issueTenantLogin($record, $data['password'] ?: null)),
                    Tables\Actions\EditAction::make()
                        ->mutateFormDataUsing(fn (array $data) => $this->mutateRentalData($data)),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    /** End-tenancy modal — triggered by clicking an active tenancy's status badge. */
    protected function endRentalAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('endRental')
            ->label(__('End tenancy'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->visible(fn (Rental $record) => $record->isActive())
            ->modalWidth('md')
            ->modalHeading(fn (Rental $record) => __('End tenancy for').' '.($record->occupant_name ?: __('tenant')))
            ->modalSubmitActionLabel(__('End tenancy'))
            ->form([
                Forms\Components\DatePicker::make('end_date')->label(__('End date'))->default(now())->required(),
                Forms\Components\Select::make('status')->label(__('Outcome'))
                    ->options([
                        RentalStatus::Vacated->value => RentalStatus::Vacated->getLabel(),
                        RentalStatus::Expired->value => RentalStatus::Expired->getLabel(),
                    ])
                    ->default(RentalStatus::Vacated->value)->required(),
                Forms\Components\Toggle::make('free_room')->label(__('Mark room as available'))->default(true),
            ])
            ->action(function (Rental $record, array $data) {
                $record->update([
                    'status' => (int) $data['status'],
                    'end_date' => $data['end_date'],
                ]);

                if ($data['free_room'] ?? true) {
                    $this->getOwnerRecord()->update(['status' => \App\Enums\UnitStatus::Available]);
                }

                Notification::make()->title(__('Tenancy ended'))->success()->send();
            });
    }

    /** Stamp the owning unit's property/landlord onto the tenancy. */
    protected function mutateRentalData(array $data): array
    {
        $unit = $this->getOwnerRecord();
        $data['property_id'] = $unit->property_id;
        $data['landlord_id'] = $unit->landlord_id;

        return $data;
    }

    /** Create or reset this tenancy's dedicated login and surface the credentials. */
    protected function issueTenantLogin(Rental $rental, ?string $password = null): void
    {
        $rental->setRelation('unit', $this->getOwnerRecord());
        $result = app(RoomAccountService::class)->createForRental($rental, $password);

        Notification::make()
            ->title($result['created'] ? __('Tenant login created') : __('Tenant login reset'))
            ->body(__('Username').': **'.$result['username'].'** · '.__('Password').': **'.$result['password'].'**')
            ->success()->persistent()->send();
    }
}
