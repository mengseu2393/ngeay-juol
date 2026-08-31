<?php

namespace App\Filament\Pages;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionAccess;
use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use App\Services\ChargeRuleResolver;
use App\Services\InvoiceBuilderService;
use App\Services\MeterReadingResolver;
use App\Services\ProratingService;
use App\Services\SubscriptionService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-screen monthly billing: every active room of the selected property is a
 * row, the only inputs are the new meter readings (plus an include checkbox),
 * and a single button invoices every ready room in one run. Periods, prorating,
 * charge-rule states, meter rollovers and duplicate protection are all resolved
 * automatically — the deliberate simplification over the old multi-step wizard.
 */
class MonthlyBilling extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.monthly-billing';

    protected static ?string $slug = 'monthly-billing';

    public ?int $propertyId = null;

    public string $issueDate = '';

    /** @var array<int, array<string, mixed>> Row state per room, built by loadRooms(). */
    public array $rooms = [];

    /** Result of the last "Create invoices" run, shown as a banner until dismissed. */
    public ?array $lastRun = null;

    public bool $creatingInvoices = false;

    /** Per-request memo — the blade calls the same lookups many times per render. */
    protected array $memo = [];

    public static function getNavigationBadge(): ?string
    {
        $landlordId = auth()->user()?->effectiveLandlordId();
        if (! $landlordId) {
            return null;
        }

        // Rendered on every page of the panel (SPA navigations included), so a
        // 60s cache replaces the join-count with a single cache read.
        $count = cache()->remember(
            "monthly-billing-badge:{$landlordId}",
            60,
            fn () => Rental::where('status', RentalStatus::Active->value)
                ->where('landlord_id', $landlordId)
                ->whereHas('unit.property.settings', fn ($q) => $q->where('monthly_billing_enabled', true))
                ->where(function ($q) {
                    $q->whereNull('next_invoice_date')
                        ->orWhereDate('next_invoice_date', '<=', now()->toDateString());
                })
                ->count(),
        );

        return $count > 0 ? (string) $count : null;
    }

    protected function memo(string $key, \Closure $fn): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $fn();
        }

        return $this->memo[$key];
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationGroup(): ?string
    {
        return ActiveProperty::id() !== null
            ? ActiveProperty::NAV_GROUP
            : 'Billing';
    }

    public static function getNavigationLabel(): string
    {
        return __('Monthly billing');
    }

    public function getTitle(): string
    {
        $name = $this->selectedProperty()?->name;

        return $name
            ? __('Monthly billing').' — '.$name
            : __('Monthly billing');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('create_invoice');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! \App\Support\SimpleLandlordMode::enabledFor(auth()->user())
            && static::canAccess();
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** The reading table wants every pixel — don't cap the content column. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::Full;
    }

    public function mount(): void
    {
        $this->issueDate = now()->toDateString();

        $activePropertyId = ActiveProperty::id();
        $visibleIds = $this->visiblePropertyIds();

        if ($activePropertyId !== null && in_array($activePropertyId, $visibleIds, true)) {
            $this->selectProperty($activePropertyId);

            return;
        }

        if (count($visibleIds) === 1) {
            $this->selectProperty((int) $visibleIds[0]);
            ActiveProperty::set($this->propertyId);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Property selection                                                 */
    /* ------------------------------------------------------------------ */

    public function visibleProperties(): Collection
    {
        return $this->memo('visibleProperties', fn () => Property::query()->with('settings')->orderBy('name')->get());
    }

    /** @return array<int, int> */
    protected function visiblePropertyIds(): array
    {
        return $this->visibleProperties()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function propertyPickerCards(): Collection
    {
        $today = Carbon::today();

        return $this->visibleProperties()
            ->map(function (Property $property) use ($today): array {
                $dueCount = count($this->dueRentalIds($property->id, $today));
                $billingEnabled = (bool) $property->settings?->monthly_billing_enabled;

                return [
                    'id' => $property->id,
                    'name' => $property->name,
                    'due_count' => $dueCount,
                    'status_label' => $billingEnabled
                        ? ($dueCount > 0 ? __('Ready for billing') : __('No rooms due'))
                        : __('Monthly billing disabled'),
                    'status_color' => $billingEnabled
                        ? ($dueCount > 0 ? 'emerald' : 'gray')
                        : 'amber',
                ];
            })
            ->sortBy(fn (array $property) => sprintf(
                '%s-%s',
                $property['due_count'] > 0 ? '0' : '1',
                Str::lower($property['name']),
            ))
            ->values();
    }

    public function chooseProperty(int $propertyId): void
    {
        if (! in_array($propertyId, $this->visiblePropertyIds(), true)) {
            return;
        }

        ActiveProperty::set($propertyId);
        $this->selectProperty($propertyId);
    }

    public function changeProperty(): void
    {
        ActiveProperty::clear();
        $this->propertyId = null;
        $this->rooms = [];
        $this->lastRun = null;
        $this->issueDate = now()->toDateString();
    }

    protected function selectProperty(int $propertyId): void
    {
        $this->propertyId = $propertyId;
        $this->issueDate = $this->suggestIssueDate($propertyId);
        $this->lastRun = null;
        $this->loadRooms();
    }

    public function selectedProperty(): ?Property
    {
        if (! $this->propertyId) {
            return null;
        }

        return $this->memo("property:{$this->propertyId}", fn () => Property::with('settings')->find($this->propertyId));
    }

    /* ------------------------------------------------------------------ */
    /*  Row state                                                          */
    /* ------------------------------------------------------------------ */

    public function billingEnabled(): bool
    {
        if (! $this->propertyId) {
            return false;
        }

        return (bool) $this->memo(
            "billingEnabled:{$this->propertyId}",
            fn () => PropertySetting::where('property_id', $this->propertyId)->value('monthly_billing_enabled'),
        );
    }

    public function activeUtilities(): Collection
    {
        if (! $this->propertyId) {
            return collect();
        }

        return $this->memo("activeUtilities:{$this->propertyId}", fn () => PropertyUtility::query()
            ->where('property_id', $this->propertyId)
            ->where('is_active', true)
            ->whereIn('billing_type', [BillingType::Metered->value, BillingType::Shared->value])
            ->orderBy('name')
            ->get());
    }

    /** Count for the "rooms due" stat tile — one query per request, not per render call. */
    public function dueRoomCount(): int
    {
        if (! $this->propertyId) {
            return 0;
        }

        $date = $this->issueDate ?: now()->toDateString();

        return count($this->memo(
            "dueIds:{$this->propertyId}:{$date}",
            fn () => $this->dueRentalIds($this->propertyId, Carbon::parse($date)),
        ));
    }

    protected function suggestIssueDate(?int $propertyId): string
    {
        if (! $propertyId) {
            return now()->toDateString();
        }

        $earliest = Rental::where('property_id', $propertyId)
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('next_invoice_date')
            ->orderBy('next_invoice_date')
            ->value('next_invoice_date');

        return $earliest ? Carbon::parse($earliest)->toDateString() : now()->toDateString();
    }

    /** @return array<int> */
    public function dueRentalIds(int $propertyId, Carbon $issueDate): array
    {
        return Rental::where('property_id', $propertyId)
            ->where('status', RentalStatus::Active->value)
            ->where(function (Builder $query) use ($issueDate) {
                $query->whereNull('next_invoice_date')
                    ->orWhereDate('next_invoice_date', '<=', $issueDate->toDateString());
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function updatedIssueDate(): void
    {
        if (! $this->propertyId) {
            return;
        }

        $this->lastRun = null;
        $this->loadRooms();
    }

    public function loadRooms(): void
    {
        if (! $this->propertyId || ! $this->billingEnabled()) {
            $this->rooms = [];

            return;
        }

        $issueDate = Carbon::parse($this->issueDate ?: now()->toDateString());
        $utilities = $this->activeUtilities();
        $propertySetting = PropertySetting::where('property_id', $this->propertyId)->first();
        $dueIds = $this->dueRentalIds($this->propertyId, $issueDate);

        $rentals = Rental::where('property_id', $this->propertyId)
            ->where('status', RentalStatus::Active->value)
            ->with(['unit', 'tenant'])
            ->get();

        $rooms = [];
        foreach ($rentals as $rental) {
            $rooms[] = $this->buildRoomState($rental, $utilities, $propertySetting, $issueDate, in_array($rental->id, $dueIds, true));
        }

        usort($rooms, fn (array $a, array $b) => strnatcasecmp(
            Str::lower((string) $a['room_number']),
            Str::lower((string) $b['room_number']),
        ));

        $this->rooms = array_values($rooms);
    }

    protected function buildRoomState(Rental $rental, Collection $utilities, ?PropertySetting $propertySetting, Carbon $issueDate, bool $isDue): array
    {
        $latestInvoice = Invoice::where('rental_id', $rental->id)->orderByDesc('period_end')->first();
        $isFirstInvoice = $latestInvoice === null;

        $periodStart = $isFirstInvoice
            ? Carbon::parse($rental->start_date)
            : Carbon::parse($latestInvoice->period_end)->addDay();

        $periodEnd = $issueDate->copy();
        if ($rental->end_date && $periodEnd->isAfter($rental->end_date)) {
            $periodEnd = Carbon::parse($rental->end_date);
        }

        $invalidPeriod = $periodStart->isAfter($periodEnd);

        if ($invalidPeriod) {
            $rent = 0.0;
        } else {
            $rent = $isFirstInvoice
                ? ProratingService::compute($propertySetting, (float) $rental->monthly_rent, $periodStart, $periodEnd)
                : (float) $rental->monthly_rent;
        }

        $duplicate = ! $invalidPeriod && Invoice::withoutGlobalScopes()
            ->where('rental_id', $rental->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->exists();

        $readings = [];
        $resolver = app(ChargeRuleResolver::class);
        $meterResolver = app(MeterReadingResolver::class);

        foreach ($utilities as $utility) {
            // Previous index comes from the room's ACTIVE meter when it has one
            // (its last reading, else its installed_reading); rooms with no
            // meter fall back to the latest usage row.
            $meterContext = $meterResolver->previous((int) $rental->unit_id, (int) $utility->id);

            // The charge rule (free / waived / not applicable / custom) is
            // resolved automatically for the billing date — no UI to change it
            // here, that lives in the utility & waiver screens.
            $decision = $resolver->resolve([
                'property_utility_id' => $utility->id,
                'rental_id' => $rental->id,
                'unit_id' => $rental->unit_id,
                'date' => $periodEnd->toDateString(),
            ]);

            $readings[] = [
                'property_utility_id' => $utility->id,
                'utility_name' => __($utility->name),
                'currency' => $utility->currency ?: 'USD',
                'unit_of_measure' => $utility->unit_of_measure,
                'rate' => (float) $utility->rate,
                'requires_reading' => $utility->requiresReading(),
                'previous_usage' => (float) ($meterContext['usage']?->amount_used ?? 0),
                'old_reading' => (string) $meterContext['previous'],
                'meter_id' => $meterContext['meter']?->getKey(),
                // Snapshot the meter maths so live previews stay DB-free; the
                // actual invoice run reloads the meter model.
                'meter_multiplier' => $meterContext['meter'] ? (float) $meterContext['meter']->multiplier : null,
                'meter_digits' => $meterContext['meter']?->digits,
                'new_reading' => null,
                'state' => $decision['effective_state'],
                'state_label' => ChargeRuleResolver::stateLabel((string) $decision['effective_state']),
                'state_reason' => $decision['reason'],
                'override_amount' => $decision['effective_state'] === 'custom' ? $decision['amount'] : null,
                'override_currency' => $decision['effective_state'] === 'custom' ? $decision['currency'] : null,
            ];
        }

        return [
            'rental_id' => $rental->id,
            'unit_id' => $rental->unit_id,
            'tenant_id' => $rental->tenant_id,
            'room_number' => $rental->unit?->room_number ?? '—',
            'occupant_name' => $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant')),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'period_display' => $periodStart->format('d M').' — '.$periodEnd->format('d M Y'),
            'rent' => round($rent, 2),
            'rent_currency' => $rental->monthly_rent_currency ?: 'USD',
            'is_first_invoice' => $isFirstInvoice,
            'is_due' => $isDue,
            'invalid_period' => $invalidPeriod,
            'duplicate' => $duplicate,
            // Rows that cannot bill (already invoiced / nothing to bill) start
            // unchecked and stay locked out; due rooms start checked.
            'include' => $isDue && ! $invalidPeriod && ! $duplicate,
            'utilities' => $readings,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Previews & per-row status                                          */
    /* ------------------------------------------------------------------ */

    protected function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public function formatQuantity(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return number_format($value, 0);
        }

        return number_format($value, 3, '.', '');
    }

    /**
     * Live per-cell preview: consumption (meter-aware) and the charge it would
     * produce, plus soft warnings the landlord can see before invoicing.
     */
    public function utilityPreview(int $roomIndex, int $utilityIndex): array
    {
        $room = $this->rooms[$roomIndex] ?? null;
        $utility = $room['utilities'][$utilityIndex] ?? null;

        if (! $room || ! $utility) {
            return ['charge' => 0.0, 'amount_used' => null, 'missing' => true, 'warning' => null, 'billable' => false];
        }

        $state = (string) ($utility['state'] ?? 'normal');
        $billable = ! in_array($state, ['not_applicable', 'skipped_this_cycle'], true);

        if (! $billable || ! (bool) $utility['requires_reading']) {
            return [
                'charge' => $billable ? $this->previewCharge($utility, 0.0) : 0.0,
                'amount_used' => null,
                'missing' => false,
                'warning' => null,
                'billable' => $billable,
            ];
        }

        $old = $this->parseNumber($utility['old_reading']) ?? 0.0;
        $new = $this->parseNumber($utility['new_reading']);

        if ($new === null) {
            return ['charge' => 0.0, 'amount_used' => null, 'missing' => true, 'warning' => null, 'billable' => true];
        }

        $hasMeter = ! empty($utility['meter_id']);
        $amountUsed = $this->previewConsumption($utility, $old, $new);

        $warning = null;
        if ($new < $old && ! $hasMeter) {
            $warning = __('Lower than previous reading — usage counts as :amount', ['amount' => $this->formatQuantity($amountUsed)]);
        } elseif (($utility['previous_usage'] ?? 0) > 0 && $amountUsed > ($utility['previous_usage'] * 2)) {
            $warning = __('Unusually high usage');
        }

        return [
            'charge' => $this->previewCharge($utility, $amountUsed),
            'amount_used' => $amountUsed,
            'missing' => false,
            'warning' => $warning,
            'billable' => true,
        ];
    }

    /**
     * Cycle consumption from the snapshot taken at load time — mirrors
     * UtilityMeter::consumption() (multiplier + digit rollover) and the legacy
     * max(0, new − old), without touching the database. The invoice run itself
     * still goes through MeterReadingResolver with the real model.
     */
    protected function previewConsumption(array $utility, float $old, float $new): float
    {
        if (empty($utility['meter_id'])) {
            return round(max(0, $new - $old), 3);
        }

        $delta = $new - $old;
        if ($delta < 0 && ! empty($utility['meter_digits'])) {
            $delta += 10 ** (int) $utility['meter_digits'];
        }

        return round(max(0, $delta) * (float) ($utility['meter_multiplier'] ?? 1), 3);
    }

    /**
     * Charge preview from the decision snapshot — mirrors the outcome of
     * UtilityBillingService::resolveCharge() for the states this page can
     * hold, without re-resolving charge rules per cell per keystroke. The
     * invoice run still uses the full service.
     */
    protected function previewCharge(array $utility, float $amountUsed): float
    {
        $state = (string) ($utility['state'] ?? 'normal');

        if (in_array($state, ['free', 'waived', 'not_applicable', 'skipped_this_cycle'], true)) {
            return 0.0;
        }

        if ($state === 'custom') {
            return (float) ($utility['override_amount'] ?? 0);
        }

        $decimals = Money::decimals($utility['currency'] ?? 'USD');
        $rate = (float) ($utility['rate'] ?? 0);

        return ($utility['requires_reading'] ?? true)
            ? round($amountUsed * $rate, $decimals)
            : round($rate, $decimals);
    }

    public function roomHasMissingReadings(int $index): bool
    {
        foreach (array_keys($this->rooms[$index]['utilities'] ?? []) as $utilityIndex) {
            if ($this->utilityPreview($index, (int) $utilityIndex)['missing']) {
                return true;
            }
        }

        return false;
    }

    /** Whether the row would produce an invoice if "Create invoices" ran now. */
    public function roomIsReady(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;

        return $room
            && ($room['include'] ?? false)
            && ! ($room['invalid_period'] ?? false)
            && ! ($room['duplicate'] ?? false)
            && ! $this->roomHasMissingReadings($index);
    }

    /** @return array{key: string, label: string, color: string} */
    public function roomStatus(int $index): array
    {
        $room = $this->rooms[$index] ?? [];

        if ($room['duplicate'] ?? false) {
            return ['key' => 'billed', 'label' => __('Already billed'), 'color' => 'gray'];
        }
        if ($room['invalid_period'] ?? false) {
            return ['key' => 'nothing', 'label' => __('Nothing to bill'), 'color' => 'gray'];
        }
        if (! ($room['include'] ?? false)) {
            return ['key' => 'excluded', 'label' => __('Not included'), 'color' => 'gray'];
        }
        if ($this->roomHasMissingReadings($index)) {
            return ['key' => 'needs_reading', 'label' => __('Needs reading'), 'color' => 'amber'];
        }

        return ['key' => 'ready', 'label' => __('Ready'), 'color' => 'emerald'];
    }

    public function readyRoomCount(): int
    {
        return collect(array_keys($this->rooms))
            ->filter(fn ($index) => $this->roomIsReady((int) $index))
            ->count();
    }

    public function includedRoomCount(): int
    {
        return collect($this->rooms)->where('include', true)->count();
    }

    public function selectableRoomIndexes(): array
    {
        return collect($this->rooms)
            ->keys()
            ->filter(fn ($index) => ! ($this->rooms[$index]['duplicate'] ?? false)
                && ! ($this->rooms[$index]['invalid_period'] ?? false))
            ->values()
            ->all();
    }

    public function toggleSelectAll(): void
    {
        $selectable = $this->selectableRoomIndexes();
        $allIncluded = collect($selectable)->every(fn ($index) => $this->rooms[$index]['include'] ?? false);

        foreach ($selectable as $index) {
            $this->rooms[$index]['include'] = ! $allIncluded;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Totals & formatting                                                */
    /* ------------------------------------------------------------------ */

    /** @return array{usd: float, khr: float} */
    public function roomTotals(int $index): array
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room || ($room['invalid_period'] ?? false)) {
            return ['usd' => 0.0, 'khr' => 0.0];
        }

        $usd = 0.0;
        $khr = 0.0;

        foreach (array_keys($room['utilities']) as $utilityIndex) {
            $preview = $this->utilityPreview($index, (int) $utilityIndex);
            $currency = strtoupper((string) ($room['utilities'][$utilityIndex]['currency'] ?? 'USD'));
            if ($currency === 'KHR') {
                $khr += $preview['charge'];
            } else {
                $usd += $preview['charge'];
            }
        }

        if (strtoupper((string) ($room['rent_currency'] ?? 'USD')) === 'KHR') {
            $khr += (float) $room['rent'];
        } else {
            $usd += (float) $room['rent'];
        }

        return ['usd' => $usd, 'khr' => $khr];
    }

    /** Sum over the rooms that would actually be invoiced right now. */
    public function grandTotalDisplay(): string
    {
        $usd = 0.0;
        $khr = 0.0;

        foreach (array_keys($this->rooms) as $index) {
            if (! $this->roomIsReady((int) $index)) {
                continue;
            }
            $totals = $this->roomTotals((int) $index);
            $usd += $totals['usd'];
            $khr += $totals['khr'];
        }

        return $this->formatMixedTotal($usd, $khr);
    }

    public function formatMixedTotal(float $usd, float $khr): string
    {
        if ($usd > 0 && $khr > 0) {
            return Money::format($usd, 'USD').' + '.Money::format($khr, 'KHR');
        }
        if ($khr > 0) {
            return Money::format($khr, 'KHR');
        }

        return Money::format($usd, 'USD');
    }

    public function getAccess(): SubscriptionAccess
    {
        return SubscriptionService::effectiveAccess(auth()->user());
    }

    /** @return array{rate: float|int, source: string, date: string} */
    public function exchangeRateInfo(): array
    {
        $setting = PropertySetting::where('property_id', $this->propertyId)->first();

        return [
            'rate' => $setting?->usd_khr_exchange_rate ?: 4000,
            'source' => $setting?->exchange_rate_source ?: __('Manual'),
            'date' => $setting?->exchange_rate_date ? $setting->exchange_rate_date->format('d M Y') : '—',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Invoice creation                                                   */
    /* ------------------------------------------------------------------ */

    public function createInvoices(): void
    {
        if ($this->creatingInvoices) {
            return;
        }

        if ($this->getAccess() === SubscriptionAccess::ReadOnly) {
            Notification::make()
                ->title(__('Write actions are disabled until payment is completed.'))
                ->warning()
                ->send();

            return;
        }

        $readyIndexes = collect(array_keys($this->rooms))
            ->filter(fn ($index) => $this->roomIsReady((int) $index))
            ->values();

        if ($readyIndexes->isEmpty()) {
            Notification::make()
                ->title(__('No rooms are ready to bill.'))
                ->body(__('Tick the rooms you want to bill and enter their new readings first.'))
                ->warning()
                ->send();

            return;
        }

        $this->creatingInvoices = true;

        $builder = app(InvoiceBuilderService::class);
        $issueDate = Carbon::parse($this->issueDate ?: now()->toDateString());
        $created = 0;
        $failed = 0;
        $invoiceIds = [];
        $failures = [];
        $billedRooms = [];

        foreach ($readyIndexes as $index) {
            $room = $this->rooms[$index];

            try {
                $invoice = DB::transaction(fn () => $this->createInvoiceForRoom($room, $builder, $issueDate));

                if ($invoice === null) {
                    // A concurrent run created the same period meanwhile.
                    continue;
                }

                $invoiceIds[] = $invoice->id;
                $billedRooms[] = (string) $room['room_number'];
                $created++;
            } catch (\Throwable $throwable) {
                report($throwable);
                $failed++;
                $failures[] = [
                    'room_number' => (string) ($room['room_number'] ?? __('Unknown room')),
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $skipped = $this->includedRoomCount() - $readyIndexes->count();

        $this->lastRun = [
            'created' => $created,
            'skipped' => max(0, $skipped),
            'failed' => $failed,
            'invoice_ids' => $invoiceIds,
            'failures' => $failures,
            'rooms' => $billedRooms,
        ];

        $this->creatingInvoices = false;

        // Re-derive the table: billed rooms now carry an invoice for the
        // period, so they surface as "Already billed" instead of re-billable.
        $this->loadRooms();

        Notification::make()
            ->title(__('Billing complete'))
            ->body(__('Created :count invoice(s).', ['count' => $created]))
            ->{$failed > 0 ? 'warning' : 'success'}()
            ->send();
    }

    /** Runs inside a transaction; returns null when the period got invoiced concurrently. */
    protected function createInvoiceForRoom(array $room, InvoiceBuilderService $builder, Carbon $issueDate): ?Invoice
    {
        $rental = Rental::withoutGlobalScopes()->with(['unit', 'property', 'tenant'])->findOrFail($room['rental_id']);
        $periodStart = Carbon::parse($room['period_start']);
        $periodEnd = Carbon::parse($room['period_end']);

        $existing = Invoice::withoutGlobalScopes()
            ->where('rental_id', $rental->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->exists();

        if ($existing) {
            return null;
        }

        $usages = [];
        $utilityOverrides = [];

        foreach ($room['utilities'] as $utility) {
            $state = (string) ($utility['state'] ?? 'normal');
            if ($state !== 'normal') {
                $utilityOverrides[$utility['property_utility_id']] = [
                    'state' => $state,
                    'reason' => $utility['state_reason'] ?? null,
                    'amount' => $utility['override_amount'] ?? null,
                    'currency' => $utility['override_currency'] ?? null,
                ];
            }

            if (in_array($state, ['not_applicable', 'skipped_this_cycle'], true)) {
                continue;
            }

            $requiresReading = (bool) ($utility['requires_reading'] ?? true);
            $newReading = $this->parseNumber($utility['new_reading']);
            if ($requiresReading && $newReading === null) {
                continue;
            }

            $oldReading = $this->parseNumber($utility['old_reading']) ?? 0.0;
            // max(0, new - old), except a meter also applies its multiplier and
            // unwraps a digit rollover.
            $meter = isset($utility['meter_id']) && $utility['meter_id']
                ? UtilityMeter::find($utility['meter_id'])
                : null;
            $amountUsed = $requiresReading
                ? app(MeterReadingResolver::class)->consumption($oldReading, (float) $newReading, $meter)
                : 0.0;

            $usages[] = UtilityUsage::updateOrCreate(
                [
                    'unit_id' => $rental->unit_id,
                    'rental_id' => $rental->id,
                    'property_utility_id' => $utility['property_utility_id'],
                    'reading_date' => $periodEnd->toDateString(),
                ],
                [
                    'landlord_id' => $rental->landlord_id,
                    'recorded_by_id' => auth()->id(),
                    'reading_type' => ReadingType::Actual,
                    'old_reading' => $oldReading,
                    'new_reading' => $requiresReading ? $newReading : null,
                    'amount_used' => $amountUsed,
                    'is_waived' => false,
                ],
            );
        }

        $params = [
            'rental' => $rental,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issue_date' => $issueDate,
            'include_rent' => true,
            'is_first_invoice' => (bool) ($room['is_first_invoice'] ?? false),
            'usages' => $usages,
            'utility_overrides' => $utilityOverrides,
        ];

        if ($dueDate = $this->determineDueDate($rental, $periodStart)) {
            $params['due_date'] = $dueDate;
        }

        $invoice = $builder->create($params);

        // Advance the schedule only when this run billed the period the
        // schedule expected — an out-of-band period must not move it.
        $shouldAdvanceSchedule = true;
        if ($rental->next_invoice_date !== null) {
            $expectedStart = Carbon::parse($rental->next_invoice_date);
            if ($periodStart->toDateString() !== $expectedStart->toDateString()) {
                $shouldAdvanceSchedule = false;
            }
        }

        if ($shouldAdvanceSchedule) {
            $rental->withoutEvents(fn () => $rental->update([
                'next_invoice_date' => $periodEnd->copy()->addDay()->startOfMonth(),
            ]));
        }

        return $invoice;
    }

    protected function determineDueDate(Rental $rental, Carbon $periodStart): ?Carbon
    {
        if (! $rental->unit?->due_date) {
            return null;
        }

        $dueDay = Carbon::parse($rental->unit->due_date)->day;
        $dueDate = $periodStart->copy()->day($dueDay);

        if ($dueDate->isBefore($periodStart)) {
            $dueDate->addMonth();
        }

        return $dueDate;
    }

    public function dismissLastRun(): void
    {
        $this->lastRun = null;
    }

    public function viewInvoicesUrl(): string
    {
        return InvoiceResource::getUrl('index');
    }
}
