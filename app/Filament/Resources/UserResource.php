<?php

namespace App\Filament\Resources;

use App\Enums\UserStatus;
use App\Filament\Forms\LocationFields;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Tables\RowActionGroup;
use App\Models\User;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        // Follow the active-property sidebar group (labelled with the property's
        // name) like the utility and other property resources. Users aren't
        // property-scoped, so we only mirror the grouping — never the query —
        // and fall back to Administration when no property is active.
        return ActiveProperty::id() !== null
            ? ActiveProperty::NAV_GROUP
            : 'Administration';
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    protected static function translateRole(?string $role): string
    {
        return match ($role) {
            'super_admin' => __('Super admin'),
            'support' => __('Support'),
            'landlord' => __('Landlord'),
            'landlord_manager' => __('Landlord manager'),
            'tenant' => __('Tenant'),
            default => (string) $role,
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Account'))
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->email()->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('username')
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText(__('Login for room/tenant accounts (no email required).')),
                    Forms\Components\TextInput::make('phone_number')->tel(),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        // the User 'hashed' cast hashes the plain value on save
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state)),
                    Forms\Components\Select::make('status')
                        ->options(UserStatus::class)
                        ->default(UserStatus::Active)
                        ->required(),
                ])->columns(2),

            Forms\Components\Section::make(__('Personal info'))
                ->schema([
                    Forms\Components\Select::make('gender')
                        ->options([
                            'male' => __('Male'),
                            'female' => __('Female'),
                            'other' => __('Other'),
                        ])
                        ->placeholder(__('Select gender')),
                    Forms\Components\DatePicker::make('dob')
                        ->label(__('Date of birth'))
                        ->maxDate(now()),
                    Forms\Components\TextInput::make('nationality')
                        ->placeholder(__('e.g. Khmer, Vietnamese')),
                ])->columns(3),

            Forms\Components\Section::make(__('Location'))
                ->schema(LocationFields::make())
                ->columns(2),

            Forms\Components\Section::make(__('Tenant details'))
                ->description(__('Extra info for tenants — occupation, emergency contacts, guarantor.'))
                ->relationship('tenantProfile')
                ->schema([
                    Forms\Components\TextInput::make('id_card_number')->label(__('ID card number')),
                    Forms\Components\TextInput::make('occupation'),
                    Forms\Components\TextInput::make('workplace')
                        ->placeholder(__('e.g. company name')),
                    Forms\Components\TextInput::make('monthly_income')
                        ->numeric()->prefix('$'),
                    Forms\Components\DatePicker::make('move_in_date')
                        ->label(__('Move-in date')),

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

                    Forms\Components\Textarea::make('notes')
                        ->label(__('Notes'))
                        ->placeholder(__('Private notes about this tenant'))
                        ->columnSpanFull(),
                ])->columns(2)
                ->collapsed(),

            Forms\Components\Section::make(__('Access'))
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->relationship('roles', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record): string => self::translateRole($record->name))
                        ->multiple()
                        ->preload()
                        ->searchable(),
                    Forms\Components\Select::make('manages_landlord_id')
                        ->label(__('Manages landlord'))
                        ->relationship('managesLandlord', 'name')
                        ->searchable()
                        ->helperText(__('For a landlord_manager: the landlord they act on behalf of.')),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('username')->searchable()->copyable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('Phone number'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge()
                    ->label(__('Roles'))
                    ->formatStateUsing(fn (?string $state): string => self::translateRole($state)),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('User type'))
                    ->options([
                        'super_admin' => __('Super admin'),
                        'support' => __('Support'),
                        'landlord' => __('Landlord'),
                        'landlord_manager' => __('Landlord manager'),
                        'tenant' => __('Tenant'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', $data['value']))
                    )),
                Tables\Filters\Filter::make('created_at')
                    ->label(__('Created at'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('From')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Tables\Filters\Indicator::make(
                                __('From').' '.Carbon::parse($data['from'])->toFormattedDateString()
                            )->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Tables\Filters\Indicator::make(
                                __('Until').' '.Carbon::parse($data['until'])->toFormattedDateString()
                            )->removeField('until');
                        }

                        return $indicators;
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
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

    /**
     * Landlords/managers see only "their" users — tenants they created or who rent
     * one of their units. Platform staff (super_admin/support) see everyone.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isPlatformStaff()) {
            $landlordId = $user->effectiveLandlordId();

            $query->where(function (Builder $w) use ($user, $landlordId) {
                $w->where('created_by_id', $user->getKey());

                if ($landlordId !== null) {
                    $w->orWhere('created_by_id', $landlordId)
                        ->orWhereHas('rentalsAsTenant', fn (Builder $r) => $r->where('landlord_id', $landlordId));
                }
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
