<?php

namespace App\Filament\Widgets;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Filament\Resources\PropertyUtilityResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\UnitResource;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Providers\Filament\LandlordPanelProvider;
use App\Services\OpeningReadingService;
use App\Support\ActiveProperty;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * "What is still missing before this property can bill?" — the four setup steps
 * that live on four different screens, in the order they have to happen.
 *
 * Setting a property up means rooms (UnitResource's Generate rooms), a utility
 * catalog with rates (PropertyUtilityResource), an opening meter index per room
 * (its Initialize readings action) and a first tenancy (RentalResource). None of
 * those screens knows about the others, and skipping one fails quietly: no
 * utility rows means rent-only invoices, a rate of 0 bills nothing, and a
 * missing opening index bills the meter's whole lifetime on the first cycle.
 *
 * The widget disappears the moment every visible step is done — it is
 * onboarding, not a permanent fixture ({@see canView()}).
 */
class PropertySetupChecklistWidget extends Widget
{
    protected static string $view = 'filament.widgets.property-setup-checklist';

    /** Above every other dashboard widget: nothing else matters until this is done. */
    protected static ?int $sort = -6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    /**
     * The property to inspect. Passed in by ViewProperty (which may be showing a
     * property that is *not* the active one); null on the dashboard, where the
     * active-property context is the only thing to go on.
     */
    public ?int $propertyId = null;

    /** Container key prefix for the per-request step cache ({@see stepsFor()}). */
    private const CACHE_KEY = 'rw.property-setup-steps.';

    public static function canView(): bool
    {
        $property = static::contextProperty();

        return $property !== null && static::outstandingSteps($property) !== [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSteps(): array
    {
        $property = $this->propertyId !== null
            ? Property::find($this->propertyId)
            : static::contextProperty();

        return $property === null ? [] : static::stepsFor($property);
    }

    public function getPropertyName(): ?string
    {
        $property = $this->propertyId !== null
            ? Property::find($this->propertyId)
            : static::contextProperty();

        return $property?->name;
    }

    /**
     * Jump to the screen that finishes a step.
     *
     * Every target is scoped to the active property (PropertySettings and the
     * Generate rooms action refuse to work without it), so the context is
     * switched first — the property being viewed is not necessarily the one
     * selected in the sidebar switcher.
     */
    public function go(string $step): mixed
    {
        $steps = collect($this->getSteps())->keyBy('key');

        $url = $steps->get($step)['url'] ?? null;

        if ($url === null) {
            return null;
        }

        $propertyId = $this->propertyId ?? ActiveProperty::id();

        if ($propertyId !== null) {
            ActiveProperty::set($propertyId);
        }

        // Full page load: the sidebar navigation is server-computed per context.
        return $this->redirect($url, navigate: false);
    }

    /**
     * The property this widget is about, derived from the request alone so the
     * static {@see canView()} can answer before Livewire has mounted anything.
     *
     * On a PropertyResource page the route's own record wins (that page passes
     * the same id in as $propertyId); everywhere else it is the sidebar context.
     */
    protected static function contextProperty(): ?Property
    {
        $routeName = (string) Route::currentRouteName();
        $record = request()->route('record');

        if ($record !== null && str_contains($routeName, '.properties.')) {
            $property = $record instanceof Property
                ? $record
                : Property::find($record);

            if ($property !== null) {
                return $property;
            }
        }

        $activeId = ActiveProperty::id();

        return $activeId === null ? null : Property::find($activeId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function outstandingSteps(Property $property): array
    {
        return array_values(array_filter(
            static::stepsFor($property),
            fn (array $step): bool => ! $step['done'],
        ));
    }

    /**
     * Every step the current user is allowed to act on, done or not.
     *
     * Memoised per property — canView() and the render pass both ask, and the
     * opening-reading check costs three queries per metered utility. The cache
     * lives in the container rather than a static property so it dies with the
     * request: a static would hand a stale answer to the next request in a
     * long-lived worker (and to the next test, which starts row ids over at 1).
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function stepsFor(Property $property): array
    {
        $cacheKey = self::CACHE_KEY.$property->getKey();

        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $panel = LandlordPanelProvider::ID;

        $roomCount = $property->units()->count();

        /** @var Collection<int, PropertyUtility> $utilities */
        $utilities = $property->propertyUtilities()->where('is_active', true)->get();
        $unpriced = $utilities->filter(fn (PropertyUtility $u): bool => (float) $u->rate <= 0);

        $steps = [];

        if (UnitResource::canViewAny()) {
            $steps[] = [
                'key' => 'rooms',
                'icon' => 'heroicon-o-squares-plus',
                'label' => __('Add rooms'),
                'description' => $roomCount > 0
                    ? trans_choice(':count room|:count rooms', $roomCount, ['count' => $roomCount])
                    : __('Use “Generate rooms” to create a whole floor at once.'),
                'done' => $roomCount > 0,
                'blocked' => false,
                'url' => UnitResource::getUrl('index', panel: $panel),
                'cta' => __('Add rooms'),
            ];
        }

        if (PropertyUtilityResource::canViewAny()) {
            $steps[] = [
                'key' => 'utilities',
                'icon' => 'heroicon-o-light-bulb',
                'label' => __('Set up utilities'),
                'description' => $utilities->isNotEmpty()
                    ? $utilities->pluck('name')->map(fn (string $name): string => __($name))->implode(', ')
                    : __('Without a utility catalog, invoices carry rent only.'),
                'done' => $utilities->isNotEmpty(),
                'blocked' => false,
                'url' => PropertyUtilityResource::getUrl('index', panel: $panel),
                'cta' => __('Set up utilities'),
            ];

            // Nothing to price yet: neither done nor actionable. A green tick
            // here would claim "every utility has a rate" about an empty catalog.
            $ratesBlocked = $utilities->isEmpty();

            $steps[] = [
                'key' => 'rates',
                'icon' => 'heroicon-o-banknotes',
                'label' => __('Set utility rates'),
                'description' => match (true) {
                    $ratesBlocked => __('Comes after the utility catalog.'),
                    $unpriced->isNotEmpty() => __('No rate yet: :names', [
                        'names' => $unpriced->pluck('name')->map(fn (string $name): string => __($name))->implode(', '),
                    ]),
                    default => __('Every utility has a rate.'),
                },
                'done' => ! $ratesBlocked && $unpriced->isEmpty(),
                'blocked' => $ratesBlocked,
                'url' => PropertyUtilityResource::getUrl('index', panel: $panel),
                'cta' => __('Set rates'),
            ];

            // A meter index needs both a room to read and a utility to read it
            // for; without either there is nothing to record yet.
            $readingsBlocked = $roomCount === 0 || $utilities->isEmpty();
            $roomsMissingOpening = static::roomsMissingOpeningReadings($property, $utilities, $roomCount);

            $steps[] = [
                'key' => 'readings',
                'icon' => 'heroicon-o-clipboard-document-list',
                'label' => __('Record opening meter readings'),
                'description' => match (true) {
                    $readingsBlocked => __('Comes after rooms and utilities.'),
                    $roomsMissingOpening > 0 => trans_choice(
                        ':count room has no opening index|:count rooms have no opening index',
                        $roomsMissingOpening,
                        ['count' => $roomsMissingOpening],
                    ),
                    default => __('Every room has a starting index to bill from.'),
                },
                'done' => ! $readingsBlocked && $roomsMissingOpening === 0,
                'blocked' => $readingsBlocked,
                'url' => PropertyUtilityResource::getUrl('index', panel: $panel),
                'cta' => __('Initialize readings'),
            ];
        }

        if (RentalResource::canViewAny()) {
            $hasTenancy = $property->rentals()->where('status', RentalStatus::Active->value)->exists();

            $steps[] = [
                'key' => 'tenants',
                'icon' => 'heroicon-o-user-plus',
                'label' => __('Move in your first tenant'),
                'description' => $hasTenancy
                    ? __('This property has an active tenancy.')
                    : __('Rooms only start billing once someone is living in them.'),
                'done' => $hasTenancy,
                'blocked' => false,
                'url' => RentalResource::getUrl('index', panel: $panel),
                'cta' => __('Add tenant'),
            ];
        }

        app()->instance($cacheKey, $steps);

        return $steps;
    }

    /**
     * Rooms still missing an opening index, counted across every metered utility
     * (a room read for electricity but not water is still counted once).
     *
     * Skipped entirely — 0, "nothing to do" — when there is nothing to read yet:
     * with no rooms or no metered utility the earlier steps are the real blocker,
     * and {@see OpeningReadingService::rows()} would cost three queries to say so.
     */
    protected static function roomsMissingOpeningReadings(Property $property, iterable $utilities, int $roomCount): int
    {
        if ($roomCount === 0) {
            return 0;
        }

        $metered = collect($utilities)
            ->filter(fn (PropertyUtility $u): bool => $u->billing_type === BillingType::Metered);

        if ($metered->isEmpty()) {
            return 0;
        }

        $service = app(OpeningReadingService::class);
        $pending = [];

        foreach ($metered as $utility) {
            foreach ($service->rows($utility) as $unitId => $row) {
                if (in_array($row['state'], [OpeningReadingService::OPEN_METER, OpeningReadingService::OPEN_LEGACY], true)) {
                    $pending[$unitId] = true;
                }
            }
        }

        return count($pending);
    }
}
