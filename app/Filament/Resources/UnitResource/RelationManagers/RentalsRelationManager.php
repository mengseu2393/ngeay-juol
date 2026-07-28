<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Services\RoomAccountService;
use App\Services\TenancyService;
use App\Support\Money;
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
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('monthly_rent')
                                ->label(__('Monthly rent'))
                                ->numeric()
                                ->default(fn () => $this->getOwnerRecord()->rent_amount)
                                ->required(),
                            Forms\Components\Select::make('monthly_rent_currency')
                                ->label(__('Rent currency'))
                                ->options([
                                    'USD' => 'USD ($)',
                                    'KHR' => 'KHR (៛)',
                                ])
                                ->default(fn () => $this->getOwnerRecord()->rent_currency ?: 'USD')
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
                                ->default(fn () => $this->getOwnerRecord()->rent_currency ?: 'USD')
                                ->required(),
                        ]),
                    Forms\Components\DatePicker::make('start_date')
                        ->default(now())
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

                    // end_date is kept in the DB but hidden from the day-to-day edit form.
                    // Use "End tenancy" (the status-badge action) to close a tenancy.
                    Forms\Components\DatePicker::make('end_date')
                        ->hidden()
                        ->dehydrated(),

                    Forms\Components\DatePicker::make('next_invoice_date')
                        ->label(__('Invoice date'))
                        ->helperText(__('The date that will auto-fill the “Issue date” on the next billing run for this tenant. Rolls forward automatically after each invoice is generated.'))
                        ->placeholder(__('Set when first invoice is due'))
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make(__('Agreement & Emergency / Guarantor Details'))
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('occupant_address')->label(__('Address'))->columnSpanFull(),
                    
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

                    Forms\Components\Textarea::make('terms_conditions')->label(__('Terms & conditions'))->columnSpanFull(),
                    Forms\Components\Textarea::make('notes')
                        ->label(__('Notes'))
                        ->placeholder(__('Private notes about this tenancy'))
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('Additional Occupants'))
                ->description(__('Co-tenants and dependents sharing this room. The primary tenant is the person set in the Tenancy section above.'))
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('primary_occupant_badge')
                        ->label('')
                        ->content(fn (?Rental $record) => $record?->occupant_name
                            ? new \Illuminate\Support\HtmlString(
                                '<span style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.375rem 0.75rem;background:rgb(209 250 229);border-radius:0.375rem;font-weight:600;color:rgb(22 101 52);">★ '
                                . e(__('Primary Tenant')) . ': ' . e($record->occupant_name) . '</span>'
                            )
                            : __('The primary tenant will be set from the Tenancy section above.'))
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('additional_occupants')
                        ->label('')
                        ->schema([
                            Forms\Components\Hidden::make('id'),
                            Forms\Components\Select::make('role')
                                ->label(__('Role'))
                                ->options([
                                    'co_tenant' => __('Co-Tenant'),
                                    'dependent' => __('Dependent'),
                                ])
                                ->default('co_tenant')
                                ->required(),
                            Forms\Components\TextInput::make('occupant_name')
                                ->label(__('Full name'))
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('occupant_phone')
                                ->label(__('Phone'))
                                ->tel(),
                            Forms\Components\TextInput::make('occupant_id_card')
                                ->label(__('ID card number')),
                            Forms\Components\Select::make('occupant_gender')
                                ->label(__('Gender'))
                                ->options([
                                    'male'   => __('Male'),
                                    'female' => __('Female'),
                                    'other'  => __('Other'),
                                ]),
                            Forms\Components\DatePicker::make('occupant_dob')
                                ->label(__('Date of birth'))
                                ->maxDate(now()),
                            Forms\Components\TextInput::make('occupant_nationality')
                                ->label(__('Nationality'))
                                ->placeholder(__('e.g. Khmer, Vietnamese')),
                            Forms\Components\TextInput::make('occupant_workplace')
                                ->label(__('Workplace'))
                                ->placeholder(__('e.g. company name')),
                            Forms\Components\SpatieMediaLibraryFileUpload::make('id_cards')
                                ->collection('id_cards')
                                ->label(__('ID card photos'))
                                ->image()
                                ->multiple()
                                ->reorderable()
                                ->maxFiles(4)
                                ->helperText(__('Front/back of national ID, passport, etc.'))
                                ->columnSpanFull(),

                        ])
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string =>
                            (! empty($state['occupant_name']) ? $state['occupant_name'] : __('New occupant'))
                            . ' — '
                            . match ($state['role'] ?? 'co_tenant') {
                                'co_tenant' => __('Co-Tenant'),
                                'dependent' => __('Dependent'),
                                default     => '',
                            })
                        ->collapsible()
                        ->addActionLabel(__('+ Add occupant'))
                        ->maxItems(fn () => max(1, ($this->getOwnerRecord()->max_occupants ?? 20) - 1))
                        ->defaultItems(0)
                        ->helperText(fn () => __('This room allows up to :max occupants (including the primary tenant).', [
                            'max' => $this->getOwnerRecord()->max_occupants ?? __('unlimited'),
                        ])),
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
                Tables\Columns\TextColumn::make('next_invoice_date')
                    ->label(__('Invoice date'))
                    ->date('d M Y')
                    ->placeholder(__('—'))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('monthly_rent')
                    ->formatStateUsing(fn ($state, Rental $record) => Money::formatForRecord($state, $record)),
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

                        // Auto-create the primary occupant record from the rental's occupant fields.
                        if (! empty($data['occupant_name'])) {
                            $rental->occupants()->create([
                                'role'                           => 'primary',
                                'user_id'                        => $rental->tenant_id,
                                'occupant_name'                  => $data['occupant_name'],
                                'occupant_phone'                 => $data['occupant_phone'] ?? null,
                                'occupant_id_card'               => $data['occupant_id_card'] ?? null,
                                'occupant_address'               => $data['occupant_address'] ?? null,
                                'occupant_gender'                => $data['occupant_gender'] ?? null,
                                'occupant_dob'                   => $data['occupant_dob'] ?? null,
                                'occupant_nationality'           => $data['occupant_nationality'] ?? null,
                                'occupant_workplace'             => $data['occupant_workplace'] ?? null,
                                'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
                                'emergency_contact_phone'        => $data['emergency_contact_phone'] ?? null,
                                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                                'guarantor_name'                 => $data['guarantor_name'] ?? null,
                                'guarantor_phone'                => $data['guarantor_phone'] ?? null,
                                'guarantor_id_number'            => $data['guarantor_id_number'] ?? null,
                                'guarantor_address'              => $data['guarantor_address'] ?? null,
                            ]);
                        }

                        // Create additional occupants (co-tenants / dependents).
                        foreach ($data['additional_occupants'] ?? [] as $occupant) {
                            if (empty($occupant['occupant_name'])) {
                                continue;
                            }
                            $newOccupant = $rental->occupants()->create([
                                'role'                 => $occupant['role'] ?? 'co_tenant',
                                'occupant_name'        => $occupant['occupant_name'],
                                'occupant_phone'       => $occupant['occupant_phone'] ?? null,
                                'occupant_id_card'     => $occupant['occupant_id_card'] ?? null,
                                'occupant_gender'      => $occupant['occupant_gender'] ?? null,
                                'occupant_dob'         => $occupant['occupant_dob'] ?? null,
                                'occupant_nationality' => $occupant['occupant_nationality'] ?? null,
                                'occupant_workplace'   => $occupant['occupant_workplace'] ?? null,
                            ]);

                            if (isset($occupant['id_cards'])) {
                                $newOccupant->syncFromMediaLibraryRequest($occupant['id_cards'])->toMediaCollection('id_cards');
                            }
                        }

                        return $rental;
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->mutateRecordDataUsing(fn (array $data, Rental $record) => $this->loadAdditionalOccupants($data, $record)),
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
                        ->mutateRecordDataUsing(fn (array $data, Rental $record) => $this->loadAdditionalOccupants($data, $record))
                        ->mutateFormDataUsing(fn (array $data) => $this->mutateRentalData($data))
                        ->after(function (Rental $record, array $data) {
                            $this->syncPrimaryOccupant($record, $data);
                            $this->syncAdditionalOccupants($record, $data['additional_occupants'] ?? []);
                        }),
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

    /** Load additional (non-primary) occupants into the form data for view/edit. */
    protected function loadAdditionalOccupants(array $data, Rental $record): array
    {
        $data['additional_occupants'] = $record->occupants()
            ->where('role', '!=', 'primary')
            ->get()
            ->map(fn ($o) => [
                'id'                   => $o->id,
                'role'                 => $o->getRawOriginal('role'),
                'occupant_name'        => $o->occupant_name,
                'occupant_phone'       => $o->occupant_phone,
                'occupant_id_card'     => $o->occupant_id_card,
                'occupant_gender'      => $o->occupant_gender,
                'occupant_dob'         => $o->occupant_dob?->format('Y-m-d'),
                'occupant_nationality' => $o->occupant_nationality,
                'occupant_workplace'   => $o->occupant_workplace,
                'id_cards'             => is_array($o->id_cards) ? $o->id_cards : [],
            ])
            ->values()
            ->toArray();

        return $data;
    }

    /** Sync the primary occupant record when the rental's occupant fields are updated. */
    protected function syncPrimaryOccupant(Rental $record, array $data): void
    {
        if (empty($data['occupant_name'])) {
            return;
        }

        $record->occupants()->updateOrCreate(
            ['rental_id' => $record->id, 'role' => 'primary'],
            [
                'user_id'                        => $record->tenant_id,
                'occupant_name'                  => $data['occupant_name'],
                'occupant_phone'                 => $data['occupant_phone'] ?? null,
                'occupant_id_card'               => $data['occupant_id_card'] ?? null,
                'occupant_address'               => $data['occupant_address'] ?? null,
                'occupant_gender'                => $data['occupant_gender'] ?? null,
                'occupant_dob'                   => $data['occupant_dob'] ?? null,
                'occupant_nationality'           => $data['occupant_nationality'] ?? null,
                'occupant_workplace'             => $data['occupant_workplace'] ?? null,
                'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone'        => $data['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'guarantor_name'                 => $data['guarantor_name'] ?? null,
                'guarantor_phone'                => $data['guarantor_phone'] ?? null,
                'guarantor_id_number'            => $data['guarantor_id_number'] ?? null,
                'guarantor_address'              => $data['guarantor_address'] ?? null,
            ]
        );
    }

    /** Sync additional occupants: update existing, create new, delete removed. */
    protected function syncAdditionalOccupants(Rental $record, array $occupants): void
    {
        $existingIds = $record->occupants()
            ->where('role', '!=', 'primary')
            ->pluck('id')
            ->toArray();

        $keepIds = [];

        foreach ($occupants as $occupant) {
            if (empty($occupant['occupant_name'])) {
                continue;
            }

            $fields = [
                'role'                 => $occupant['role'] ?? 'co_tenant',
                'occupant_name'        => $occupant['occupant_name'],
                'occupant_phone'       => $occupant['occupant_phone'] ?? null,
                'occupant_id_card'     => $occupant['occupant_id_card'] ?? null,
                'occupant_gender'      => $occupant['occupant_gender'] ?? null,
                'occupant_dob'         => $occupant['occupant_dob'] ?? null,
                'occupant_nationality' => $occupant['occupant_nationality'] ?? null,
                'occupant_workplace'   => $occupant['occupant_workplace'] ?? null,
            ];

            $id = ! empty($occupant['id']) ? (int) $occupant['id'] : null;

            if ($id && in_array($id, $existingIds, true)) {
                $occupantModel = $record->occupants()->where('id', $id)->first();
                if ($occupantModel) {
                    $occupantModel->update($fields);
                    if (isset($occupant['id_cards'])) {
                        $occupantModel->syncFromMediaLibraryRequest($occupant['id_cards'])->toMediaCollection('id_cards');
                    }
                    $keepIds[] = $id;
                }
            } else {
                $new = $record->occupants()->create($fields);
                if (isset($occupant['id_cards'])) {
                    $new->syncFromMediaLibraryRequest($occupant['id_cards'])->toMediaCollection('id_cards');
                }
                $keepIds[] = $new->id;
            }
        }

        // Remove occupants that were deleted from the repeater.
        $record->occupants()
            ->where('role', '!=', 'primary')
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
