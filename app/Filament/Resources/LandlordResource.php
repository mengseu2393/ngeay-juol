<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LandlordResource\Pages;
use App\Filament\Resources\LandlordResource\RelationManagers;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Super-admin-only directory of landlords (users with the `landlord` role).
 * Select a landlord to drill into everything they own — properties, units,
 * tenancies and billing — via the view page's relation managers.
 */
class LandlordResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'landlords';

    public static function getNavigationLabel(): string
    {
        return __('Landlords');
    }

    public static function getModelLabel(): string
    {
        return __('Landlord');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Landlords');
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
                        ->helperText('Login name for the landlord.'),
                    Forms\Components\TextInput::make('phone_number')->tel(),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state)),
                    Forms\Components\Select::make('status')
                        ->options(UserStatus::class)
                        ->default(UserStatus::Active)
                        ->required(),
                ])->columns(2),

            Forms\Components\Section::make(__('Company & banking'))
                ->relationship('landlordProfile')
                ->schema([
                    Forms\Components\TextInput::make('company_name')->maxLength(255),
                    Forms\Components\TextInput::make('bank_name')->maxLength(255),
                    Forms\Components\TextInput::make('bank_account_name')->maxLength(255),
                    Forms\Components\TextInput::make('bank_account_number')->maxLength(255),
                ])->columns(2),
        ]);
    }

    /** Only super admins manage the landlord directory. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /** Restrict the resource to users that hold the `landlord` role. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'landlord'))
            ->withCount(['properties', 'rentalsAsLandlord']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone_number')->label('Phone')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('landlordProfile.company_name')->label('Company')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('properties_count')->label('Properties')->badge()->sortable(),
                Tables\Columns\TextColumn::make('rentals_as_landlord_count')->label('Tenancies')->badge()->color('gray')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->defaultSort('name');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Landlord'))
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('email')->placeholder('—'),
                    Infolists\Components\TextEntry::make('username')->placeholder('—'),
                    Infolists\Components\TextEntry::make('phone_number')->label('Phone')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('properties_count')->label('Properties')->badge(),
                ])->columns(3),

            Infolists\Components\Section::make(__('Company & banking'))
                ->schema([
                    Infolists\Components\TextEntry::make('landlordProfile.company_name')->label('Company')->placeholder('—'),
                    Infolists\Components\TextEntry::make('landlordProfile.bank_name')->label('Bank')->placeholder('—'),
                    Infolists\Components\TextEntry::make('landlordProfile.bank_account_name')->label('Account name')->placeholder('—'),
                    Infolists\Components\TextEntry::make('landlordProfile.bank_account_number')->label('Account number')->placeholder('—'),
                ])->columns(2)
                ->visible(fn (User $record) => $record->landlordProfile !== null),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PropertiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandlords::route('/'),
            'create' => Pages\CreateLandlord::route('/create'),
            'view' => Pages\ViewLandlord::route('/{record}'),
            'edit' => Pages\EditLandlord::route('/{record}/edit'),
        ];
    }
}
