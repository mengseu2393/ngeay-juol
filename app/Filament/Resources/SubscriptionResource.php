<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Filament\Resources\SubscriptionResource\RelationManagers;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'subscriptions';

    public static function getNavigationLabel(): string
    {
        return __('Subscriptions');
    }

    public static function getModelLabel(): string
    {
        return __('Subscription');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Subscriptions');
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
            Forms\Components\Section::make(__('Landlord'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->label('Landlord')
                        ->options(fn () => User::role('landlord')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->disabled(fn (string $operation) => $operation === 'edit'),
                    Forms\Components\Select::make('plan_id')
                        ->label('Plan')
                        ->relationship('plan', 'name')
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('status')
                        ->options(SubscriptionStatus::class)
                        ->required(),
                ])->columns(3),

            Forms\Components\Section::make(__('Plan snapshot'))
                ->schema([
                    Forms\Components\TextInput::make('price')->required()->numeric()->prefix('$'),
                    Forms\Components\TextInput::make('max_units')->numeric()->placeholder('Unlimited'),
                    Forms\Components\TextInput::make('max_properties')->numeric()->placeholder('Unlimited'),
                    Forms\Components\DatePicker::make('starts_at')->required(),
                    Forms\Components\DatePicker::make('ends_at')->required(),
                    Forms\Components\DatePicker::make('grace_ends_at'),
                    Forms\Components\DatePicker::make('trial_ends_at'),
                    Forms\Components\Toggle::make('auto_renew'),
                ])->columns(3),
        ]);
    }

    /** Scoped to landlords only. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['plan', 'landlord']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('landlord.name')
                    ->label('Landlord')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_unit_count')
                    ->label('Units')
                    ->badge()
                    ->color('gray')
                    ->suffix(fn ($record) => $record->max_units ? " / {$record->max_units}" : ''),
                Tables\Columns\TextColumn::make('ends_at')
                    ->date()
                    ->sortable()
                    ->color(fn ($record) => match (true) {
                        $record->ends_at && $record->ends_at->isPast() => 'danger',
                        $record->ends_at && $record->ends_at->diffInDays(now()) <= 7 => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('grace_ends_at')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('auto_renew')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name'),
                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Expiring within 7 days')
                    ->query(fn (Builder $q) => $q
                        ->where('ends_at', '>=', now())
                        ->where('ends_at', '<=', now()->addDays(7))
                    ),
                Tables\Filters\Filter::make('past_due')
                    ->label('Past due')
                    ->query(fn (Builder $q) => $q
                        ->where('ends_at', '<', now())
                        ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
                    ),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    // Renew
                    Tables\Actions\Action::make('renew')
                        ->label('Renew')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->form([
                            Forms\Components\TextInput::make('amount')
                                ->required()->numeric()->prefix('$')
                                ->default(fn (Subscription $record) => $record->price),
                            Forms\Components\Select::make('method')
                                ->label('Payment method')
                                ->options(\App\Enums\PaymentMethod::class)
                                ->default(\App\Enums\PaymentMethod::BankTransfer->value),
                            Forms\Components\DatePicker::make('paid_at')
                                ->default(now()),
                            Forms\Components\Textarea::make('note')->rows(2),
                        ])
                        ->action(function (Subscription $record, array $data): void {
                            SubscriptionService::renew($record, $data);
                        })
                        ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::Suspended),
                    // Extend
                    Tables\Actions\Action::make('extend')
                        ->label('Extend')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('info')
                        ->form([
                            Forms\Components\TextInput::make('days')
                                ->required()->numeric()->minValue(1)->default(30),
                            Forms\Components\Textarea::make('reason')->required()->rows(2),
                        ])
                        ->action(fn (Subscription $record, array $data) => SubscriptionService::extend($record, $data['days'], $data['reason'])),
                    // Shorten
                    Tables\Actions\Action::make('shorten')
                        ->label('Shorten')
                        ->icon('heroicon-o-arrow-left-circle')
                        ->color('warning')
                        ->form([
                            Forms\Components\TextInput::make('days')
                                ->required()->numeric()->minValue(1)->default(7),
                            Forms\Components\Textarea::make('reason')->required()->rows(2),
                        ])
                        ->action(fn (Subscription $record, array $data) => SubscriptionService::shorten($record, $data['days'], $data['reason'])),
                    // Change plan
                    Tables\Actions\Action::make('changePlan')
                        ->label('Change plan')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('gray')
                        ->form([
                            Forms\Components\Select::make('plan_id')
                                ->label('New plan')
                                ->options(fn () => SubscriptionPlan::where('is_active', true)->pluck('name', 'id'))
                                ->required(),
                            Forms\Components\Toggle::make('immediate')
                                ->label('Apply immediately (upgrade)')
                                ->default(true)
                                ->helperText('Off = apply at period end (downgrade).'),
                        ])
                        ->action(function (Subscription $record, array $data): void {
                            $plan = SubscriptionPlan::findOrFail($data['plan_id']);
                            SubscriptionService::changePlan($record, $plan, $data['immediate']);
                        }),
                    // Cancel
                    Tables\Actions\Action::make('cancel')
                        ->label('Cancel')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')->rows(2),
                            Forms\Components\Toggle::make('immediate')
                                ->label('Cancel immediately')
                                ->helperText('Off = cancel at period end (runs until ends_at).'),
                        ])
                        ->action(fn (Subscription $record, array $data) => SubscriptionService::cancel(
                            $record,
                            $data['reason'] ?? null,
                            $data['immediate'] ?? false
                        ))
                        ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::Cancelled),
                    // Suspend
                    Tables\Actions\Action::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-o-pause-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')->required()->rows(2),
                        ])
                        ->action(fn (Subscription $record, array $data) => SubscriptionService::suspend($record, $data['reason']))
                        ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::Suspended),
                    // Reactivate
                    Tables\Actions\Action::make('reactivate')
                        ->label('Reactivate')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Subscription $record) => SubscriptionService::reactivate($record))
                        ->visible(fn (Subscription $record) => $record->status === SubscriptionStatus::Suspended),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Landlord & plan'))
                ->schema([
                    Infolists\Components\TextEntry::make('landlord.name'),
                    Infolists\Components\TextEntry::make('plan.name')->badge(),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('price')
                        ->money(fn ($record) => $record->currency),
                    Infolists\Components\IconEntry::make('auto_renew')->boolean(),
                ])->columns(3),

            Infolists\Components\Section::make(__('Period'))
                ->schema([
                    Infolists\Components\TextEntry::make('starts_at')->date(),
                    Infolists\Components\TextEntry::make('ends_at')->date()
                        ->color(fn ($record) => $record->ends_at?->isPast() ? 'danger' : 'success'),
                    Infolists\Components\TextEntry::make('grace_ends_at')->date()->placeholder('—'),
                    Infolists\Components\TextEntry::make('trial_ends_at')->date()->placeholder('—'),
                ])->columns(4),

            Infolists\Components\Section::make(__('Usage'))
                ->schema([
                    Infolists\Components\TextEntry::make('current_unit_count')
                        ->label('Units used')
                        ->suffix(fn ($record) => $record->max_units ? " / {$record->max_units}" : ' / Unlimited'),
                    Infolists\Components\TextEntry::make('max_properties')
                        ->label('Max properties')
                        ->placeholder('Unlimited'),
                ])->columns(2),

            Infolists\Components\Section::make(__('Suspension / Cancellation'))
                ->schema([
                    Infolists\Components\TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    Infolists\Components\TextEntry::make('cancellation_reason')->placeholder('—'),
                    Infolists\Components\TextEntry::make('suspended_at')->dateTime()->placeholder('—'),
                    Infolists\Components\TextEntry::make('suspension_reason')->placeholder('—'),
                ])->columns(2)
                ->visible(fn ($record) => $record->cancelled_at || $record->suspended_at),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionHistoryRelationManager::class,
            RelationManagers\SubscriptionPaymentRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'view' => Pages\ViewSubscription::route('/{record}'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
