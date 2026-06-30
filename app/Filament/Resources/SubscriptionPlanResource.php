<?php

namespace App\Filament\Resources;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'subscription-plans';

    public static function getNavigationLabel(): string
    {
        return __('Plans');
    }

    public static function getModelLabel(): string
    {
        return __('Plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plans');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Plan details'))
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->required()->maxLength(255)->unique(ignoreRecord: true)
                        ->helperText('URL-friendly identifier.'),
                    Forms\Components\Textarea::make('description')->rows(3),
                    Forms\Components\Select::make('billing_model')
                        ->options(PlanBillingModel::class)->required(),
                    Forms\Components\Select::make('interval')
                        ->options(PlanInterval::class)->required(),
                    Forms\Components\TextInput::make('price')
                        ->required()->numeric()->prefix('$')->maxValue(999999),
                    Forms\Components\TextInput::make('unit_price')
                        ->numeric()->prefix('$')->maxValue(999999)
                        ->visible(fn (Get $get) => in_array($get('billing_model'), [2, 3])),
                    Forms\Components\TextInput::make('currency')
                        ->required()->maxLength(3)->default('USD'),
                ])->columns(3),

            Forms\Components\Section::make(__('Limits'))
                ->schema([
                    Forms\Components\TextInput::make('max_units')
                        ->numeric()->minValue(0)->placeholder('Unlimited'),
                    Forms\Components\TextInput::make('max_properties')
                        ->numeric()->minValue(0)->placeholder('Unlimited'),
                    Forms\Components\TextInput::make('trial_days')
                        ->numeric()->default(0)->suffix('days'),
                    Forms\Components\TextInput::make('grace_days')
                        ->numeric()->default(0)->suffix('days')
                        ->helperText('0 = use platform default (7 days).'),
                ])->columns(4),

            Forms\Components\Section::make(__('Features'))
                ->schema([
                    Forms\Components\KeyValue::make('features')
                        ->keyLabel('Feature key')
                        ->valueLabel('Enabled')
                        ->addActionLabel('Add feature'),
                ]),

            Forms\Components\Section::make(__('Visibility'))
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active (available for new subscriptions)')
                        ->default(true),
                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()->default(0),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->badge()->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('billing_model')->badge()->sortable(),
                Tables\Columns\TextColumn::make('interval')->badge()->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_units')
                    ->label('Max units')
                    ->badge()->color('gray'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()->sortable(),
                Tables\Columns\TextColumn::make('subscriptions_count')
                    ->label('Subscribers')
                    ->counts('subscriptions')
                    ->badge()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('is_active')
                    ->query(fn (Builder $q) => $q->where('is_active', true))
                    ->default(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (SubscriptionPlan $record) => $record->subscriptions()->count() === 0),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'view' => Pages\ViewSubscriptionPlan::route('/{record}'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
