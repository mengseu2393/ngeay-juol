<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\TenantResource\RelationManagers\TenanciesRelationManager;
use App\Filament\Tables\RowActionGroup;
use App\Models\Property;
use App\Models\Rental;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\ActiveProperty;
use App\Support\Receivables;
use App\Support\SimpleLandlordMode;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The people, not the paperwork: one row per tenant account, across every
 * property and every room they have ever rented from this landlord.
 *
 * {@see RentalResource} lists *tenancies* — a tenant who moved from room 101 to
 * room 205 is two unrelated rows there, and there is no way to ask "where does
 * Sok live now?" or "what does this person still owe me?" without knowing which
 * tenancy to open first. This resource answers those questions directly.
 *
 * It is deliberately read-only: tenant accounts are created and edited from the
 * tenancy (RentalResource / the room account service) so that the account and
 * its tenancy stay in step. Nothing here writes.
 *
 * Model is {@see User}, so authorization runs through {@see UserPolicy}
 * and the Shield `*_user` permissions the landlord roles already hold — this
 * resource adds no permission of its own, and {@see self::getEloquentQuery()}
 * re-applies UserResource's visibility rule so it can never show more.
 */
class TenantResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'tenants';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Tenancy';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('Tenant');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tenants');
    }

    /** "Tenants" is already RentalResource's nav label (tenancies) — stay distinct. */
    public static function getNavigationLabel(): string
    {
        return __('Tenant directory');
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Simple Mode has its own cut-down navigation, like every other resource.
        return ! SimpleLandlordMode::enabledFor(auth()->user())
            && parent::shouldRegisterNavigation();
    }

    /**
     * Tenant rows visible to the current user.
     *
     * The landlord/manager branch is duplicated deliberately from
     * {@see UserResource::getEloquentQuery()} — that is the canonical
     * tenant-visibility rule (accounts they created, or who rent one of their
     * units) and this resource must not be one line weaker than it. Keep the two
     * in sync; a divergence here is a cross-tenant data leak, not a cosmetic bug.
     *
     * On top of it: only accounts holding the `tenant` role, never staff.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', 'tenant'))
            ->with([
                // Every tenancy of this tenant, newest first — LandlordScope keeps
                // another landlord's tenancy for the same person out of the list.
                'rentalsAsTenant' => fn ($relation) => $relation
                    ->with(['unit', 'property'])
                    ->orderByDesc('start_date'),
                // NOTE: constrained on purpose — this relation holds ONLY the open
                // invoices, so the table can total an outstanding balance without an
                // N+1. Anything needing the full invoice history (the relation
                // manager) must query the relation afresh.
                'invoicesAsTenant' => fn ($relation) => $relation
                    ->whereIn('payment_status', self::openInvoiceStatuses())
                    ->with('property.settings'),
            ]);

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Tenant'))
                    ->weight('bold')
                    ->description(fn (User $record): ?string => $record->username)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('Phone'))
                    ->icon('heroicon-m-phone')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('room')
                    ->label(__('Room'))
                    ->state(fn (User $record): ?string => self::currentTenancy($record)?->unit?->room_number)
                    ->description(fn (User $record): ?string => self::currentTenancy($record)?->property?->name)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('tenancy_status')
                    ->label(__('Tenancy'))
                    ->badge()
                    ->state(fn (User $record): ?RentalStatus => self::currentTenancy($record)?->status)
                    ->placeholder(__('No tenancy')),

                Tables\Columns\IconColumn::make('has_portal_login')
                    ->label(__('Portal login'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->falseColor('gray')
                    ->state(fn (User $record): bool => self::hasPortalLogin($record)),

                Tables\Columns\TextColumn::make('outstanding')
                    ->label(__('Outstanding'))
                    ->alignEnd()
                    ->state(fn (User $record): string => Receivables::format(self::openBalance($record)))
                    ->color(fn (User $record): string => self::owesMoney($record) ? 'danger' : 'gray')
                    ->weight(fn (User $record): ?string => self::owesMoney($record) ? 'bold' : null),

                Tables\Columns\TextColumn::make('moved_in')
                    ->label(__('Moved in'))
                    ->date()
                    ->state(fn (User $record) => self::currentTenancy($record)?->start_date)
                    ->placeholder('—'),
            ])
            ->filters([
                // Defaulted to the sidebar's active property rather than hard-scoped
                // to it: a directory exists to find someone across the portfolio, and
                // a tenant who moved out of the selected property must stay findable.
                // As a filter the narrowing is visible in the indicator bar and one
                // click to remove; as a query scope it would be neither.
                Tables\Filters\SelectFilter::make('property')
                    ->label(__('Property'))
                    ->options(fn (): array => Property::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(ActiveProperty::id())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas(
                            'rentalsAsTenant',
                            fn (Builder $r) => $r->where('property_id', $data['value']),
                        ),
                    )),

                Tables\Filters\TernaryFilter::make('has_portal_login')
                    ->label(__('Portal login'))
                    ->placeholder(__('All tenants'))
                    ->trueLabel(__('Has portal login'))
                    ->falseLabel(__('No portal login'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(
                            fn (Builder $w) => $w->whereNotNull('username')->orWhereNotNull('email'),
                        ),
                        false: fn (Builder $query): Builder => $query->whereNull('username')->whereNull('email'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('openTenancy')
                        ->label(__('Open tenancy'))
                        ->icon('heroicon-o-key')
                        ->color('gray')
                        ->visible(fn (User $record): bool => self::currentTenancy($record) !== null)
                        ->url(fn (User $record): string => RentalResource::getUrl(
                            'view',
                            ['record' => self::currentTenancy($record)],
                        )),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading(__('No tenants found'))
            ->emptyStateDescription(__('Tenants appear here once you add a tenancy with a tenant account.'));
    }

    public static function getRelations(): array
    {
        return [
            TenanciesRelationManager::class,
            InvoicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'view' => Pages\ViewTenant::route('/{record}'),
        ];
    }

    // ---------------------------------------------------------------------
    // Row helpers (shared by the table and the view page)
    // ---------------------------------------------------------------------

    /**
     * The tenancy this tenant is living in — the active one, else the most recent.
     * Reads the eager-loaded relation when present, so the table stays one query.
     */
    public static function currentTenancy(User $record): ?Rental
    {
        return self::tenancies($record)->first(fn (Rental $rental): bool => $rental->status === RentalStatus::Active)
            ?? self::tenancies($record)->first();
    }

    /**
     * Whether the account can actually sign in to the tenant portal. Landlords
     * also keep tenant records for people who never got a login (paper-only
     * occupants), so this is the account's credential, not its tenancy.
     */
    public static function hasPortalLogin(User $record): bool
    {
        return filled($record->username) || filled($record->email);
    }

    /**
     * What this tenant still owes across every open invoice, in both currencies.
     *
     * @return array{usd: float, khr: float, count: int}
     */
    public static function openBalance(User $record): array
    {
        $invoices = $record->relationLoaded('invoicesAsTenant')
            ? $record->invoicesAsTenant
            : Receivables::openInvoices()->where('tenant_id', $record->getKey())->get();

        $usd = 0.0;
        $khr = 0.0;

        foreach ($invoices as $invoice) {
            $usd += $invoice->balance_usd;
            $khr += $invoice->balance_khr;
        }

        return [
            'usd' => round($usd, 2),
            'khr' => round($khr, 0),
            'count' => $invoices->count(),
        ];
    }

    public static function owesMoney(User $record): bool
    {
        $balance = self::openBalance($record);

        return $balance['usd'] > 0.0 || $balance['khr'] > 0.0;
    }

    /** @return EloquentCollection<int, Rental> */
    private static function tenancies(User $record): EloquentCollection
    {
        if (! $record->relationLoaded('rentalsAsTenant')) {
            $record->setRelation(
                'rentalsAsTenant',
                $record->rentalsAsTenant()->with(['unit', 'property'])->orderByDesc('start_date')->get(),
            );
        }

        return $record->rentalsAsTenant;
    }

    /** @return array<int, int> */
    private static function openInvoiceStatuses(): array
    {
        return array_map(fn (InvoiceStatus $status): int => $status->value, Receivables::OPEN_STATUSES);
    }
}
