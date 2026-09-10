<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionAccess;
use App\Enums\UserStatus;
use App\Filament\Forms\LocationFields;
use App\Filament\Resources\LandlordResource\Pages;
use App\Filament\Resources\LandlordResource\RelationManagers;
use App\Filament\Tables\RowActionGroup;
use App\Models\Invoice;
use App\Models\Scopes\LandlordScope;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Services\SubscriptionService;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-staff directory of landlords (users with the `landlord` role).
 * Select a landlord to drill into everything they own — properties, units,
 * tenancies and billing — via the view page's relation managers.
 *
 * Read access is open to all platform staff (super_admin + support) so a
 * support agent can answer a customer call; create/edit/delete stay
 * super-admin-only.
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
                    Forms\Components\TextInput::make('first_name')
                        ->label(__('First name'))
                        ->required()
                        ->maxLength(255)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record) {
                            if ($record) {
                                $component->state(explode(' ', (string) $record->name, 2)[0]);
                            }
                        }),
                    Forms\Components\TextInput::make('last_name')
                        ->label(__('Last name'))
                        ->maxLength(255)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record) {
                            if ($record) {
                                $component->state(explode(' ', (string) $record->name, 2)[1] ?? '');
                            }
                        }),
                    Forms\Components\TextInput::make('email')->email()->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('phone_number')->tel(),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state)),
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
                    Forms\Components\Select::make('status')
                        ->options(UserStatus::class)
                        ->default(UserStatus::Active)
                        ->required(),
                ])->columns(2),

            // Provisioning step 2. Without a Subscription row
            // SubscriptionService::effectiveAccess() returns Revoked and
            // EnsureActiveSubscription logs the landlord straight back out, so the
            // plan is captured here rather than in a separate, forgettable screen.
            Forms\Components\Section::make(__('Subscription'))
                ->description(__('A landlord cannot sign in to the landlord panel until a subscription is assigned.'))
                ->visibleOn('create')
                ->schema([
                    Forms\Components\Select::make('subscription_plan_id')
                        ->label(__('Plan'))
                        ->options(fn () => SubscriptionPlan::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->native(false)
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('subscription_trial_days')
                        ->label(__('Trial days'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->helperText(__('Leave blank to use the plan default.'))
                        ->placeholder(function (Forms\Get $get): ?string {
                            $planId = $get('subscription_plan_id');

                            return $planId
                                ? (string) (SubscriptionPlan::find($planId)?->trial_days ?? 0)
                                : null;
                        }),
                    Forms\Components\Toggle::make('subscription_auto_renew')
                        ->label(__('Auto-renew'))
                        ->default(true),
                ])->columns(3),

            Forms\Components\Section::make(__('Location'))
                ->schema(LocationFields::make())
                ->columns(2),

        ]);
    }

    // ---------------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------------

    /** Both platform-staff roles may READ the directory (see the write gates below). */
    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::actorIsSuperAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::actorIsSuperAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::actorIsSuperAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return static::actorIsSuperAdmin();
    }

    public static function canRestore(Model $record): bool
    {
        return static::actorIsSuperAdmin();
    }

    /**
     * Never offered: every landlord-owned table (properties, units, rentals,
     * invoices, subscriptions) declares `landlord_id ... restrictOnDelete()`, so a
     * hard delete of a landlord with any data raises an FK error, not a cascade.
     */
    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    protected static function actorIsSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Restrict the resource to users that hold the `landlord` role, and eager-load
     * everything the table and the customer-360 infolist read.
     *
     * The relation constraints drop {@see LandlordScope} explicitly: platform staff
     * are already unscoped by it, but this resource deliberately reads across the
     * landlord boundary and says so at the query (CLAUDE.md).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'landlord'))
            ->with([
                'subscription' => fn ($q) => $q->withoutGlobalScope(LandlordScope::class)->with('plan'),
            ])
            ->withCount([
                'properties' => fn (Builder $q) => $q->withoutGlobalScope(LandlordScope::class),
                'units' => fn (Builder $q) => $q->withoutGlobalScope(LandlordScope::class),
                'rentalsAsLandlord' => fn (Builder $q) => $q->withoutGlobalScope(LandlordScope::class),
                'rentalsAsLandlord as active_rentals_count' => fn (Builder $q) => $q
                    ->withoutGlobalScope(LandlordScope::class)
                    ->where('status', RentalStatus::Active->value),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone_number')->label(__('Phone'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('landlordProfile.company_name')->label(__('Company'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('subscription_state')
                    ->label(__('Subscription'))
                    ->badge()
                    ->state(fn (User $record): string => static::subscriptionLabel($record))
                    ->color(fn (User $record): string => $record->subscription?->status?->getColor() ?? 'danger')
                    ->icon(fn (User $record): ?string => $record->subscription ? null : 'heroicon-m-exclamation-triangle')
                    ->tooltip(fn (User $record): ?string => $record->subscription
                        ? null
                        : __('This landlord is locked out of the landlord panel until a subscription is assigned.')),
                Tables\Columns\TextColumn::make('properties_count')->label(__('Properties'))->badge()->sortable(),
                Tables\Columns\TextColumn::make('rentals_as_landlord_count')->label(__('Tenancies'))->badge()->color('gray')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Matches SubscriptionService::assign()'s own uniqueness probe, minus
                // the landlord scope: these are exactly the landlords that can be
                // given a subscription — and exactly the ones that cannot sign in.
                Tables\Filters\Filter::make('missing_subscription')
                    ->label(__('Missing subscription'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'subscription',
                        fn (Builder $subQuery) => $subQuery->withoutGlobalScope(LandlordScope::class),
                    )),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete landlord'))
                        ->modalDescription(__('The account is soft-deleted: the landlord can no longer sign in and drops out of this list unless the Trashed filter is on. Their properties, units, tenancies and invoices are kept untouched, and the account can be restored.'))
                        ->disabled(fn (User $record): bool => static::ownsProperties($record))
                        ->tooltip(fn (User $record): ?string => static::ownsProperties($record)
                            ? __('Delete or reassign this landlord\'s properties first.')
                            : null)
                        ->before(function (Tables\Actions\DeleteAction $action, User $record): void {
                            static::guardDeletion($action, $record);
                        }),
                ]),
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
                    Infolists\Components\TextEntry::make('phone_number')->label(__('Phone'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('landlordProfile.company_name')->label(__('Company'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('created_at')->label(__('Customer since'))->date(),
                ])->columns(3),

            Infolists\Components\Section::make(__('Subscription'))
                ->description(__('Whether this landlord can sign in right now, and on what terms.'))
                ->schema([
                    Infolists\Components\TextEntry::make('subscription.plan.name')
                        ->label(__('Plan'))
                        ->placeholder(__('No subscription')),
                    Infolists\Components\TextEntry::make('subscription.status')
                        ->label(__('Subscription status'))
                        ->badge()
                        ->placeholder(__('No subscription')),
                    Infolists\Components\TextEntry::make('effective_access')
                        ->label(__('Effective access'))
                        ->badge()
                        ->state(fn (User $record): string => SubscriptionService::effectiveAccess($record)->getLabel())
                        ->color(fn (User $record): string => match (SubscriptionService::effectiveAccess($record)) {
                            SubscriptionAccess::Full => 'success',
                            SubscriptionAccess::PastDue => 'warning',
                            SubscriptionAccess::ReadOnly => 'info',
                            SubscriptionAccess::Revoked => 'danger',
                        }),
                    Infolists\Components\TextEntry::make('subscription.trial_ends_at')
                        ->label(__('Trial ends'))
                        ->date()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('subscription.ends_at')
                        ->label(__('Current period ends'))
                        ->date()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('subscription.grace_ends_at')
                        ->label(__('Grace period ends'))
                        ->date()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('unit_cap_usage')
                        ->label(__('Rooms used'))
                        ->state(fn (User $record): string => static::unitCapUsage($record))
                        ->helperText(__('Counted the same way the unit cap is enforced.')),
                    Infolists\Components\IconEntry::make('subscription.auto_renew')
                        ->label(__('Auto-renew'))
                        ->boolean()
                        ->placeholder('—'),
                ])->columns(3),

            Infolists\Components\Section::make(__('Portfolio'))
                ->schema([
                    Infolists\Components\TextEntry::make('properties_count')->label(__('Properties'))->badge(),
                    Infolists\Components\TextEntry::make('units_count')->label(__('Rooms'))->badge()->color('gray'),
                    Infolists\Components\TextEntry::make('active_rentals_count')->label(__('Active tenancies'))->badge()->color('gray'),
                ])->columns(3),

            Infolists\Components\Section::make(__('Outstanding receivables'))
                ->description(__('Issued rent invoices that are not fully paid or cancelled.'))
                ->schema([
                    Infolists\Components\TextEntry::make('receivable_usd')
                        ->label(__('Outstanding (USD)'))
                        ->state(fn (User $record): string => '$'.number_format(static::receivables($record)['usd'], 2))
                        ->color(fn (User $record): string => static::receivables($record)['usd'] > 0 ? 'warning' : 'success'),
                    Infolists\Components\TextEntry::make('receivable_khr')
                        ->label(__('Outstanding (KHR)'))
                        ->state(fn (User $record): string => number_format(static::receivables($record)['khr'], 0).' ៛')
                        ->visible(fn (User $record): bool => static::receivables($record)['khr'] > 0),
                    Infolists\Components\TextEntry::make('open_invoices')
                        ->label(__('Unpaid invoices'))
                        ->badge()
                        ->color('gray')
                        ->state(fn (User $record): int => static::receivables($record)['open']),
                    Infolists\Components\TextEntry::make('overdue_invoices')
                        ->label(__('Overdue invoices'))
                        ->badge()
                        ->color(fn (User $record): string => static::receivables($record)['overdue'] > 0 ? 'danger' : 'gray')
                        ->state(fn (User $record): int => static::receivables($record)['overdue']),
                ])->columns(4),
        ]);
    }

    // ---------------------------------------------------------------------
    // Read helpers (all deliberately cross the landlord boundary)
    // ---------------------------------------------------------------------

    /** Plan + status for the table badge, or the loud "no subscription" state. */
    public static function subscriptionLabel(User $record): string
    {
        $subscription = $record->subscription;

        if (! $subscription) {
            return __('No subscription');
        }

        return ($subscription->plan?->name ?? __('Unknown plan')).' · '.$subscription->status->getLabel();
    }

    /**
     * Rooms consumed against the plan cap, counted exactly as
     * {@see SubscriptionService::assertWithinUnitCap()} counts them (unscoped,
     * including trashed rooms) so support never quotes a different number than
     * the one that blocks the landlord.
     */
    public static function unitCapUsage(User $record): string
    {
        $used = Unit::withoutGlobalScopes()->where('landlord_id', $record->getKey())->count();
        $cap = $record->subscription?->max_units;

        return $used.' / '.($cap ?: __('Unlimited'));
    }

    /**
     * One aggregate query for the receivables panel.
     *
     * `total_usd`/`total_khr` are the authoritative money columns; `amount_due`/
     * `amount_paid` are the legacy fallback that Invoice::resolvePaymentStatus()
     * uses for rows written before the twin-currency columns existed.
     *
     * @return array{usd: float, khr: float, open: int, overdue: int}
     */
    public static function receivables(User $record): array
    {
        $row = Invoice::withoutGlobalScope(LandlordScope::class)
            ->where('landlord_id', $record->getKey())
            ->whereNotIn('payment_status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Paid->value,
                InvoiceStatus::Cancelled->value,
            ])
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw('SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as overdue_count', [InvoiceStatus::Overdue->value])
            ->selectRaw('SUM(COALESCE(total_usd, amount_due) - COALESCE(paid_usd, amount_paid)) as outstanding_usd')
            ->selectRaw('SUM(COALESCE(total_khr, 0) - COALESCE(paid_khr, 0)) as outstanding_khr')
            // toBase(): hydrating an Invoice would route `balance_*`-shaped aliases
            // through the model's accessors instead of returning the aggregate.
            ->toBase()
            ->first();

        return [
            'usd' => max(0.0, round((float) ($row->outstanding_usd ?? 0), 2)),
            'khr' => max(0.0, round((float) ($row->outstanding_khr ?? 0))),
            'open' => (int) ($row->open_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
        ];
    }

    /** VERIFIED: properties.landlord_id is `restrictOnDelete`, and Property soft-deletes. */
    public static function ownsProperties(User $record): bool
    {
        return ($record->properties_count
            ?? $record->properties()->withoutGlobalScope(LandlordScope::class)->count()) > 0;
    }

    /**
     * Hard gate behind the disabled state — also covers a stale eager-loaded count.
     *
     * @param  MountableAction  $action
     */
    public static function guardDeletion($action, User $record): void
    {
        if (! static::ownsProperties($record)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Landlord still owns properties'))
            ->body(__('Delete or reassign every property this landlord owns before removing the account.'))
            ->send();

        $action->cancel();
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
