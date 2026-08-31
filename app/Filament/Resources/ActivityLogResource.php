<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only window on the `activity_log` table that Spatie's LogsActivity trait
 * already fills for Property, Unit, Rental, Invoice, PropertyUtility, UtilityMeter,
 * User, Subscription and SubscriptionPlan. Nothing writes through this resource —
 * an audit trail you can edit is not an audit trail — so create/edit/delete are off
 * and the row action only opens the before/after diff.
 *
 * Gated on the `view_activity_log` permission that RolesAndPermissionsSeeder has
 * always granted to super_admin and support, rather than on Shield-generated
 * `*_activity` permissions (hence the entry in filament-shield's exclude list).
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'system-log';

    public static function getNavigationLabel(): string
    {
        return __('System Log');
    }

    public static function getModelLabel(): string
    {
        return __('Log entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('System Log');
    }

    /** Shield generates `*_activity` permissions; this resource uses the existing one. */
    public static function getPermissionPrefixes(): array
    {
        return [];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view_activity_log');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // causer is a morphTo, so let Eloquent batch it per type instead of
            // resolving one causer per row while rendering.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Logged at'))
                    ->dateTime('d M Y H:i')
                    ->description(fn (Activity $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label(__('Event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __(Str::headline((string) $state)))
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('Affected record'))
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (?string $state) => static::subjectLabel($state))
                    ->description(fn (Activity $record) => $record->subject_id ? '#'.$record->subject_id : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer_id')
                    ->label(__('By'))
                    ->state(fn (Activity $record) => $record->causer?->name ?? __('System'))
                    ->color(fn (Activity $record) => $record->causer ? null : 'gray')
                    ->description(fn (Activity $record) => $record->causer?->email),
                Tables\Columns\TextColumn::make('changes')
                    ->label(__('Changed'))
                    ->state(fn (Activity $record) => static::changedFields($record))
                    ->badge()
                    ->color('gray')
                    // No expandableLimitedList(): its show-more/show-less toggle
                    // renders both labels at once here. The full list is in Details.
                    ->limitList(3)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label(__('Event'))
                    ->options(fn () => [
                        'created' => __('Created'),
                        'updated' => __('Updated'),
                        'deleted' => __('Deleted'),
                    ]),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label(__('Affected record'))
                    ->options(fn () => static::subjectTypeOptions()),
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label(__('By'))
                    ->options(fn () => User::query()
                        ->whereIn('id', Activity::query()->whereNotNull('causer_id')->distinct()->pluck('causer_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->label(__('Date'))
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('From')),
                        Forms\Components\DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $parts = array_filter([
                            ($data['from'] ?? null) ? __('From').' '.\Carbon\Carbon::parse($data['from'])->toFormattedDateString() : null,
                            ($data['until'] ?? null) ? __('Until').' '.\Carbon\Carbon::parse($data['until'])->toFormattedDateString() : null,
                        ]);

                        return $parts ? [Tables\Filters\Indicator::make(implode(' — ', $parts))] : [];
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label(__('Details'))
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading(fn (Activity $record) => static::subjectLabel($record->subject_type)
                        .($record->subject_id ? ' #'.$record->subject_id : ''))
                    ->modalWidth('2xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Activity $record) => view('filament.modals.activity-log-details', [
                        'activity' => $record,
                    ])),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('Nothing logged yet.'))
            ->emptyStateDescription(__('Changes to properties, rooms, tenancies, invoices and users show up here.'))
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    /** Morph alias (`property_utility`) → readable label, via the enforced morph map. */
    public static function subjectLabel(?string $type): string
    {
        if (blank($type)) {
            return '—';
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return __(Str::headline(class_basename($class)));
    }

    /**
     * Names of the fields this entry touched. `attributes` holds the new values on
     * every event; `old` only exists on updates.
     *
     * @return array<int, string>
     */
    public static function changedFields(Activity $activity): array
    {
        $attributes = $activity->properties->get('attributes') ?? [];

        if (! is_array($attributes)) {
            $attributes = (array) $attributes;
        }

        return array_map(
            fn (string $key) => Str::headline($key),
            array_keys($attributes),
        );
    }

    /** @return array<string, string> */
    protected static function subjectTypeOptions(): array
    {
        return Activity::query()
            ->select('subject_type')
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $type) => [$type => static::subjectLabel($type)])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLog::route('/'),
        ];
    }
}
