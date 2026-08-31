<?php

namespace App\Filament\Widgets;

use App\Enums\RentalStatus;
use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * Every landlord on one screen, ordered by how much of the product they use.
 *
 * The two facts worth acting on are at opposite ends of this table. At the top
 * sit the landlords pressing against their plan's room cap — the upsell
 * conversation, and the reason a room create starts failing
 * ({@see SubscriptionService::assertWithinUnitCap()}) if nobody
 * notices. At the bottom sit accounts that pay but have built nothing, which is
 * churn with a delay on it.
 *
 * "Last active" is read from the audit trail rather than the sessions table:
 * sessions are pruned on their own schedule, so an empty one means "logged out
 * a while ago", not "gone quiet in June".
 */
class AdminLandlordActivityWidget extends BaseWidget
{
    protected static ?int $sort = -34;

    protected int|string|array $columnSpan = 'full';

    /** Rooms used ÷ cap at or above this reads as "nearly full". */
    private const CAP_WARNING_RATIO = 0.8;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Landlord accounts'))
            ->description(__('Busiest first. Watch the ones near their room cap and the ones that never started.'))
            ->query(fn (): Builder => $this->landlords())
            ->defaultSort('units_count', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading(__('No landlords yet'))
            ->emptyStateIcon('heroicon-o-user-circle')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Landlord'))
                    ->description(fn (User $record): ?string => $record->landlordProfile?->company_name)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subscription.plan.name')
                    ->label(__('Plan'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('No plan')),

                Tables\Columns\TextColumn::make('units_count')
                    ->label(__('Rooms'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (int|string $state, User $record): string => $record->subscription?->max_units
                        ? (int) $state.' / '.$record->subscription->max_units
                        : (string) (int) $state)
                    ->color(fn (User $record): string => match (true) {
                        $this->capRatio($record) === null => 'gray',
                        $this->capRatio($record) >= 1.0 => 'danger',
                        $this->capRatio($record) >= self::CAP_WARNING_RATIO => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (User $record): ?string => $this->capRatio($record) === null
                        ? null
                        : __(':percent% of the plan cap', ['percent' => (int) round($this->capRatio($record) * 100)])),

                Tables\Columns\TextColumn::make('properties_count')
                    ->label(__('Properties'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_rentals_count')
                    ->label(__('Tenancies'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('subscription.status')
                    ->label(__('Subscription'))
                    ->badge()
                    ->placeholder(__('None'))
                    ->description(fn (User $record): ?string => self::expiryNote($record->subscription)),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label(__('Last active'))
                    ->since()
                    ->placeholder(__('never'))
                    ->tooltip(fn (?string $state): ?string => $state ? Carbon::parse($state)->toDayDateTimeString() : null)
                    ->sortable()
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'danger',
                        Carbon::parse($state)->lt(now()->subDays(30)) => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\Filter::make('near_cap')
                    ->label(__('Near their room cap'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'subscription',
                        fn (Builder $sub) => $sub->whereNotNull('max_units')
                            ->whereRaw('(SELECT COUNT(*) FROM units WHERE units.landlord_id = users.id AND units.deleted_at IS NULL) >= subscriptions.max_units * ?', [self::CAP_WARNING_RATIO]),
                    )),

                Tables\Filters\Filter::make('never_started')
                    ->label(__('Never added a property'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('properties')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view')
                        ->label(__('Open landlord'))
                        ->icon('heroicon-m-user-circle')
                        ->url(fn (User $record): string => LandlordResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\Action::make('subscription')
                        ->label(__('Manage subscription'))
                        ->icon('heroicon-m-credit-card')
                        ->visible(fn (User $record): bool => $record->subscription !== null)
                        ->url(fn (User $record): string => SubscriptionResource::getUrl('view', ['record' => $record->subscription])),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    /**
     * Without this, TableWidget::makeTable() overwrites the heading set in
     * table() with one derived from the class name ("Admin Landlord Activity").
     */
    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }

    /**
     * Landlords with the counts the table ranks on, plus the audit trail's most
     * recent timestamp for each as a correlated subquery — one column, no N+1,
     * and sortable in SQL, which a PHP-side rollup would not be.
     */
    private function landlords(): Builder
    {
        return User::role('landlord')
            ->with(['subscription.plan', 'landlordProfile'])
            ->withCount([
                'properties',
                'units',
                'rentalsAsLandlord as active_rentals_count' => fn (Builder $q) => $q
                    ->where('status', RentalStatus::Active->value),
            ])
            ->addSelect(['last_activity_at' => Activity::query()
                ->select('created_at')
                ->whereColumn('causer_id', 'users.id')
                ->where('causer_type', Relation::getMorphAlias(User::class))
                ->latest('created_at')
                ->limit(1),
            ]);
    }

    /** Rooms used as a fraction of the plan cap, or null when the plan is uncapped. */
    private function capRatio(User $landlord): ?float
    {
        $cap = $landlord->subscription?->max_units;

        return $cap ? (int) $landlord->units_count / $cap : null;
    }

    /** "expires in 12 days" / "expired 40 days ago" — the urgency, not the date. */
    private static function expiryNote(?Subscription $subscription): ?string
    {
        if (! $subscription?->ends_at) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($subscription->ends_at, false);

        return $days >= 0
            ? __('expires in :count days', ['count' => $days])
            : __('expired :count days ago', ['count' => abs($days)]);
    }
}
