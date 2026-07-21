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
use App\Models\UtilityUsage;
use App\Services\InvoiceBuilderService;
use App\Services\ChargeRuleResolver;
use App\Services\ProratingService;
use App\Services\SubscriptionService;
use App\Services\UtilityBillingService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desktop-first monthly billing workspace.
 */
class MonthlyBilling extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.monthly-billing';

    public bool $embedded = false;

    protected $queryString = [
        'embedded',
    ];

    public string $step = 'blocked';

    public bool $manualMode = false;

    public array $selectedRentalIds = [];

    public ?int $propertyId = null;

    public string $issueDate = '';

    public int $currentRoomIndex = 0;

    public array $rooms = [];

    public ?int $reviewFocusIndex = null;

    public bool $showCreateConfirmation = false;

    public bool $creatingInvoices = false;

    public array $resultSummary = [
        'created' => 0,
        'skipped' => 0,
        'failed' => 0,
        'invoice_ids' => [],
        'failures' => [],
    ];

    /**
     * Navigation badge: active rentals due for billing across visible properties.
     */
    public static function getNavigationBadge(): ?string
    {
        $landlordId = auth()->user()?->effectiveLandlordId();
        if (! $landlordId) {
            return null;
        }

        $count = Rental::where('status', RentalStatus::Active->value)
            ->where('landlord_id', $landlordId)
            ->whereHas('unit.property.settings', fn ($q) => $q->where('monthly_billing_enabled', true))
            ->where(function ($q) {
                $q->whereNull('next_invoice_date')
                    ->orWhereDate('next_invoice_date', '<=', now()->toDateString());
            })
            ->count();

        return $count > 0 ? (string) $count : null;
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
        return $this->selectedPropertyName()
            ? __('Monthly billing').' — '.$this->selectedPropertyName()
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

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->issueDate = now()->toDateString();

        $activePropertyId = ActiveProperty::id();
        $visiblePropertyIds = $this->visiblePropertyIds();

        if ($activePropertyId !== null && in_array($activePropertyId, $visiblePropertyIds, true)) {
            $this->hydrateSelectedProperty($activePropertyId);
            return;
        }

        if (count($visiblePropertyIds) === 1) {
            $this->hydrateSelectedProperty((int) $visiblePropertyIds[0]);
            ActiveProperty::set($this->propertyId);
            return;
        }

        $this->resetWizard();
        $this->step = 'blocked';
    }

    public function visibleProperties(): Collection
    {
        return Property::query()
            ->with('settings')
            ->orderBy('name')
            ->get();
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
                        ? ($dueCount > 0 ? 'success' : 'gray')
                        : 'warning',
                    'billing_enabled' => $billingEnabled,
                ];
            })
            ->sortBy(fn (array $property) => sprintf(
                '%s-%s',
                $property['due_count'] > 0 ? '0' : '1',
                Str::lower($property['name']),
            ))
            ->values();
    }

    public function selectedProperty(): ?Property
    {
        if (! $this->propertyId) {
            return null;
        }

        return Property::with('settings')->find($this->propertyId);
    }

    public function selectedPropertyName(): ?string
    {
        return $this->selectedProperty()?->name ?? ActiveProperty::name();
    }

    public function issueDateLabel(): string
    {
        return Carbon::parse($this->issueDate ?: now()->toDateString())->translatedFormat('j M Y');
    }

    public function currencySymbol(): string
    {
        return Money::forPropertyId($this->propertyId)
            ? Money::symbol(Money::forPropertyId($this->propertyId))
            : Money::activeSymbol();
    }

    public function formatMoney(mixed $value): string
    {
        return Money::format($value, Money::forPropertyId($this->propertyId));
    }

    public function utilityLabel(string $name): string
    {
        return __($name);
    }

    public function billingEnabled(): bool
    {
        return $this->isBillingEnabled($this->propertyId);
    }

    public function dueRoomCount(): int
    {
        if (! $this->propertyId) {
            return 0;
        }

        return count($this->dueRentalIds($this->propertyId, Carbon::parse($this->issueDate ?: now()->toDateString())));
    }

    public function activeUtilities(): Collection
    {
        if (! $this->propertyId) {
            return collect();
        }

        return PropertyUtility::query()
            ->where('property_id', $this->propertyId)
            ->where('is_active', true)
            ->whereIn('billing_type', [BillingType::Metered->value, BillingType::Shared->value])
            ->orderBy('name')
            ->get();
    }

    public function startBilling(): void
    {
        if (! $this->propertyId) {
            Notification::make()
                ->warning()
                ->title(__('Select a property from the sidebar to start billing.'))
                ->send();

            return;
        }

        if (! $this->billingEnabled()) {
            Notification::make()
                ->warning()
                ->title(__('Monthly billing is disabled for this property.'))
                ->send();

            return;
        }

        if ($this->manualMode) {
            if (count($this->selectedRentalIds) === 0) {
                Notification::make()
                    ->warning()
                    ->title(__('Please select at least one room for manual billing.'))
                    ->send();

                return;
            }
        } else {
            if ($this->dueRoomCount() === 0) {
                Notification::make()
                    ->warning()
                    ->title(__('No rooms are due for billing on this date.'))
                    ->send();

                return;
            }
        }

        $this->loadRooms();

        if ($this->rooms === []) {
            Notification::make()
                ->warning()
                ->title($this->manualMode ? __('None of the selected rooms have active rentals.') : __('No rooms are due for billing on this date.'))
                ->send();

            return;
        }

        $this->currentRoomIndex = 0;
        $this->reviewFocusIndex = null;
        $this->step = 'reading';
    }

    public function chooseProperty(int $propertyId): void
    {
        if (! in_array($propertyId, $this->visiblePropertyIds(), true)) {
            return;
        }

        ActiveProperty::set($propertyId);
        $this->hydrateSelectedProperty($propertyId);
    }

    public function resetToPropertyPicker(): void
    {
        ActiveProperty::clear();
        $this->resetWizard();
        $this->step = 'blocked';
    }

    public function beginPropertyChange(): void
    {
        $this->resetToPropertyPicker();
    }

    public function sidebarPropertyLabel(): string
    {
        return ActiveProperty::name() ?? __('All properties');
    }

    public function needsSidebarPropertySelection(): bool
    {
        return $this->propertyId === null;
    }

    public function updateIssueDate(): void
    {
        if ($this->step === 'reading' || $this->step === 'review') {
            return;
        }
    }

    public function currentRoom(): ?array
    {
        return $this->rooms[$this->currentRoomIndex] ?? null;
    }

    public function currentRoomNumber(): string
    {
        return (string) ($this->currentRoom()['room_number'] ?? '');
    }

    public function currentRoomOccupant(): string
    {
        return (string) ($this->currentRoom()['occupant_name'] ?? '');
    }

    public function currentRoomProgress(): string
    {
        return __('Room :current of :total', [
            'current' => min($this->currentRoomIndex + 1, max(1, count($this->rooms))),
            'total' => max(1, count($this->rooms)),
        ]);
    }

    public function roomSummary(int $index): array
    {
        $room = $this->rooms[$index] ?? null;

        if (! $room) {
            return [
                'utility_summary' => '',
                'charges_to_bill' => [],
                'free_or_waived' => [],
                'not_billed' => [],
                'rent' => 0.0,
                'rent_currency' => 'USD',
                'utilities_total' => 0.0,
                'estimated_total' => 0.0,
                'estimated_total_display' => '',
                'warnings' => [],
                'warning_count' => 0,
                'has_missing' => false,
                'is_skipped' => false,
                'is_complete' => false,
            ];
        }

        $usdOnly = 0.0;
        $khrOnly = 0.0;
        $summaryParts = [];
        $chargesToBill = [];
        $freeOrWaived = [];
        $notBilled = [];
        $warnings = [];
        $hasMissing = false;

        $pStart = Carbon::parse($room['period_start']);
        $pEnd = Carbon::parse($room['period_end']);
        if ($pStart->isAfter($pEnd)) {
            $warnings[] = __('Period start cannot be after period end');
        }
        if ($this->hasDuplicateInvoice($index)) {
            $warnings[] = __('Duplicate invoice found for this period');
        }

        foreach ($room['utilities'] as $utilityIndex => $utility) {
            $preview = $this->utilityPreview($index, $utilityIndex);
            $utilityCurrency = $utility['currency'] ?? 'USD';
            $state = (string) ($utility['state_override'] ?? 'normal');
            $stateLabel = ChargeRuleResolver::stateLabel($state);
            $scopeLabel = ChargeRuleResolver::scopeLabel((string) ($utility['source_scope_type'] ?? 'property'));
            if ($utilityCurrency === 'USD') {
                $usdOnly += $preview['charge'];
            } else {
                $khrOnly += $preview['charge'];
            }

            $summaryParts[] = $utility['utility_name'].': '.$stateLabel.' · '.$scopeLabel;

            if (in_array($state, ['not_applicable', 'skipped_this_cycle'], true)) {
                $notBilled[] = $utility['utility_name'].' · '.$stateLabel;
            } elseif (in_array($state, ['free', 'waived'], true)) {
                $freeOrWaived[] = $utility['utility_name'].' · '.$stateLabel;
            } else {
                $chargesToBill[] = $utility['utility_name'].' · '.$stateLabel;
            }

            if ($preview['warning']) {
                $warnings[] = $preview['warning'];
            }

            if ($preview['missing']) {
                $hasMissing = true;
            }
        }

        $rent = (float) ($room['rent'] ?? 0);
        $rentCurrency = $room['rent_currency'] ?? 'USD';
        if ($rentCurrency === 'USD') {
            $usdOnly += $rent;
        } else {
            $khrOnly += $rent;
        }

        $propertySetting = PropertySetting::where('property_id', $this->propertyId)->first();
        $rate = $propertySetting?->usd_khr_exchange_rate ?: 4000;

        return [
            'utility_summary' => $summaryParts !== []
                ? implode(' · ', $summaryParts)
                : __('No active utilities'),
            'charges_to_bill' => $chargesToBill,
            'free_or_waived' => $freeOrWaived,
            'not_billed' => $notBilled,
            'rent' => $rent,
            'rent_currency' => $rentCurrency,
            'utilities_total' => $usdOnly + ($khrOnly / $rate),
            'estimated_total' => round($usdOnly + ($khrOnly / $rate), 2),
            'estimated_total_display' => $this->formatMixedTotal($usdOnly, $khrOnly, $rate),
            'warnings' => array_values(array_unique($warnings)),
            'warning_count' => count(array_unique($warnings)),
            'has_missing' => $hasMissing,
            'is_skipped' => $room['skipped'] ?? false,
            'is_complete' => ! $hasMissing && count($warnings) === 0,
        ];
    }

    public function formatMixedTotal(float $usd, float $khr, float $rate): string
    {
        if ($usd > 0 && $khr > 0) {
            return Money::format($usd, 'USD') . ' + ' . Money::format($khr, 'KHR');
        }
        if ($khr > 0) {
            return Money::format($khr, 'KHR');
        }
        return Money::format($usd, 'USD');
    }

    public function getExchangeRateInfo(): array
    {
        $setting = PropertySetting::where('property_id', $this->propertyId)->first();
        return [
            'rate' => $setting?->usd_khr_exchange_rate ?: 4000,
            'source' => $setting?->exchange_rate_source ?: __('Manual'),
            'date' => $setting?->exchange_rate_date ? $setting->exchange_rate_date->format('d M Y') : '—',
        ];
    }

    public function utilityPreview(int $roomIndex, int $utilityIndex): array
    {
        $room = $this->rooms[$roomIndex] ?? null;
        $utility = $room['utilities'][$utilityIndex] ?? null;

        if (! $room || ! $utility) {
            return [
                'old_reading' => null,
                'new_reading' => null,
                'amount_used' => null,
                'charge' => 0.0,
                'warning' => null,
                'missing' => true,
                'requires_reading' => true,
            ];
        }

        $state = (string) ($utility['state_override'] ?? 'normal');
        if (in_array($state, ['not_applicable', 'skipped_this_cycle'], true)) {
            return [
                'old_reading' => null,
                'new_reading' => null,
                'amount_used' => null,
                'charge' => $this->previewCharge($utility, 0.0),
                'warning' => null,
                'is_lower_reading' => false,
                'is_high_usage' => false,
                'missing' => false,
                'requires_reading' => false,
            ];
        }

        if (! (bool) ($utility['requires_reading'] ?? true)) {
            return [
                'old_reading' => null,
                'new_reading' => null,
                'amount_used' => null,
                'charge' => $this->previewCharge($utility, 0.0),
                'warning' => null,
                'is_lower_reading' => false,
                'is_high_usage' => false,
                'missing' => false,
                'requires_reading' => false,
            ];
        }

        $old = $this->parseNumber($utility['old_reading']) ?? 0.0;
        $new = $this->parseNumber($utility['new_reading']);
        $missing = $new === null;
        $amountUsed = $new === null ? null : max(0, round($new - $old, 3));

        $warning = null;
        $isLowerReading = $new !== null && $new < $old;
        $isHighUsage = false;

        if ($isLowerReading && blank($utility['override_reason'] ?? null)) {
            $warning = __('Lower than previous reading');
        } elseif ($new !== null && $amountUsed !== null) {
            $previousUsage = (float) ($utility['previous_usage'] ?? 0);
            if ($previousUsage > 0 && $amountUsed > ($previousUsage * 2)) {
                $warning = __('Unusually high usage');
                $isHighUsage = true;
            }
        }

        $charge = 0.0;
        if ($new !== null) {
            $charge = $this->previewCharge($utility, $amountUsed ?? 0.0);
        }

        return [
            'old_reading' => $old,
            'new_reading' => $new,
            'amount_used' => $amountUsed,
            'charge' => $charge,
            'warning' => $warning,
            'is_lower_reading' => $isLowerReading,
            'is_high_usage' => $isHighUsage,
            'missing' => $missing,
            'requires_reading' => true,
        ];
    }

    public function roomWarnings(int $index): array
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room || ($room['skipped'] ?? false)) {
            return [];
        }

        $warnings = [];

        foreach (array_keys($room['utilities']) as $utilityIndex) {
            if (in_array((string) ($room['utilities'][$utilityIndex]['state_override'] ?? 'normal'), ['not_applicable', 'skipped_this_cycle'], true)) {
                continue;
            }
            $preview = $this->utilityPreview($index, (int) $utilityIndex);
            if (($preview['requires_reading'] ?? true) && $preview['warning']) {
                $warnings[] = $preview['warning'];
            }
            if (($preview['requires_reading'] ?? true) && $preview['missing']) {
                $warnings[] = __('Missing reading');
            }
        }

        return array_values(array_unique($warnings));
    }

    public function roomHasBlockingLowReadings(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room || ($room['skipped'] ?? false)) {
            return false;
        }

        foreach ($room['utilities'] as $utilityIndex => $utility) {
            if (in_array((string) ($utility['state_override'] ?? 'normal'), ['not_applicable', 'skipped_this_cycle'], true)) {
                continue;
            }
            $preview = $this->utilityPreview($index, $utilityIndex);
            if (($preview['requires_reading'] ?? true) && $preview['is_lower_reading'] && blank($utility['override_reason'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function roomHasMissingReadings(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room || ($room['skipped'] ?? false)) {
            return false;
        }

        foreach ($room['utilities'] as $utilityIndex => $utility) {
            if (in_array((string) ($utility['state_override'] ?? 'normal'), ['not_applicable', 'skipped_this_cycle'], true)) {
                continue;
            }
            if (($utility['requires_reading'] ?? true) && blank($utility['new_reading'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function roomIsReady(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;

        return $room
            && ! ($room['skipped'] ?? false)
            && ! $this->roomHasMissingReadings($index)
            && ! $this->roomHasBlockingLowReadings($index);
    }

    public function completeRoomCount(): int
    {
        return collect(array_keys($this->rooms))
            ->filter(fn ($index) => $this->roomIsReady((int) $index))
            ->count();
    }

    public function skippedRoomCount(): int
    {
        return collect($this->rooms)->filter(fn (array $room) => (bool) ($room['skipped'] ?? false))->count();
    }

    protected function billingStateCounts(): array
    {
        $counts = [
            'billed' => 0,
            'free' => 0,
            'waived' => 0,
            'not_applicable' => 0,
            'skipped_this_cycle' => 0,
            'custom' => 0,
        ];

        foreach ($this->rooms as $room) {
            if (($room['skipped'] ?? false) === true) {
                continue;
            }

            $rentState = $room['rent_state_override'] ?? 'normal';
            if ($rentState === 'free' || $rentState === 'waived') {
                $counts[$rentState]++;
            } elseif ($rentState === 'not_applicable' || $rentState === 'skipped_this_cycle') {
                $counts[$rentState]++;
            } else {
                $counts['billed']++;
                if ($rentState === 'custom') {
                    $counts['custom']++;
                }
            }

            foreach ($room['utilities'] ?? [] as $utility) {
                $state = $utility['state_override'] ?? 'normal';
                if ($state === 'free' || $state === 'waived') {
                    $counts[$state]++;
                } elseif ($state === 'not_applicable' || $state === 'skipped_this_cycle') {
                    $counts[$state]++;
                } else {
                    $counts['billed']++;
                    if ($state === 'custom') {
                        $counts['custom']++;
                    }
                }
            }
        }

        return $counts;
    }

    public function roomsWithWarningsCount(): int
    {
        return collect(array_keys($this->rooms))
            ->filter(fn ($index) => $this->roomWarnings((int) $index) !== [])
            ->count();
    }

    public function estimatedInvoiceCount(): int
    {
        return collect(array_keys($this->rooms))
            ->filter(fn ($index) => $this->roomIsReady((int) $index))
            ->count();
    }

    public function nextRoom(): void
    {
        if ($this->rooms === []) {
            return;
        }

        if ($this->currentRoomIndex >= count($this->rooms) - 1) {
            $this->goToReview();
            return;
        }

        $this->currentRoomIndex++;
    }

    public function advanceFromReading(int $roomIndex, int $utilityIndex): void
    {
        if (! isset($this->rooms[$roomIndex]['utilities'][$utilityIndex])) {
            $this->nextRoom();

            return;
        }

        $nextIndex = null;
        $utilities = $this->rooms[$roomIndex]['utilities'];

        for ($i = $utilityIndex + 1; $i < count($utilities); $i++) {
            if (! ($utilities[$i]['requires_reading'] ?? true) || blank($utilities[$i]['new_reading'] ?? null)) {
                $nextIndex = $i;
                break;
            }
        }

        if ($nextIndex === null && $utilityIndex + 1 < count($utilities)) {
            $nextIndex = $utilityIndex + 1;
        }

        if ($nextIndex !== null) {
            $this->dispatch('focus-reading', ref: 'reading-'.$roomIndex.'-'.$nextIndex);

            return;
        }

        $this->nextRoom();
    }

    public function previousRoom(): void
    {
        if ($this->reviewFocusIndex !== null) {
            $this->returnToReview();

            return;
        }

        if ($this->currentRoomIndex <= 0) {
            $this->step = 'start';

            return;
        }

        $this->currentRoomIndex--;
    }

    public function skipCurrentRoom(): void
    {
        if (! isset($this->rooms[$this->currentRoomIndex])) {
            return;
        }

        $this->rooms[$this->currentRoomIndex]['skipped'] = true;
        $this->rooms[$this->currentRoomIndex]['skip_reason'] = __('Skipped by user');
        $this->nextRoom();
    }

    public function toggleRoomSkip(int $index): void
    {
        if (! isset($this->rooms[$index])) {
            return;
        }

        $this->rooms[$index]['skipped'] = ! (bool) ($this->rooms[$index]['skipped'] ?? false);
        if (! $this->rooms[$index]['skipped']) {
            unset($this->rooms[$index]['skip_reason']);
        }
    }

    public function editRoom(int $index): void
    {
        if (! isset($this->rooms[$index])) {
            return;
        }

        $this->currentRoomIndex = $index;
        $this->reviewFocusIndex = $index;
        $this->step = 'reading';
    }

    public function returnToReview(): void
    {
        $this->step = 'review';
    }

    public function goToReview(): void
    {
        if ($this->rooms === []) {
            return;
        }

        $blockingIndex = $this->firstBlockingRoomIndex();
        if ($blockingIndex !== null) {
            $this->currentRoomIndex = $blockingIndex;
            $this->step = 'reading';
            $this->reviewFocusIndex = $blockingIndex;

            Notification::make()
                ->warning()
                ->title(__('Please finish or override the highlighted room before review.'))
                ->send();

            return;
        }

        $this->step = 'review';
    }

    public function openCreateConfirmation(): void
    {
        if ($this->rooms === []) {
            return;
        }

        if ($this->firstBlockingRoomIndex() !== null) {
            Notification::make()
                ->warning()
                ->title(__('Complete or skip the blocked rooms before creating invoices.'))
                ->send();

            return;
        }

        $this->showCreateConfirmation = true;
    }

    public function cancelCreateConfirmation(): void
    {
        $this->showCreateConfirmation = false;
    }

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

        if ($this->rooms === []) {
            Notification::make()
                ->title(__('No rooms are due for billing on this date.'))
                ->warning()
                ->send();

            return;
        }

        if (($blockingIndex = $this->firstBlockingRoomIndex()) !== null) {
            $this->currentRoomIndex = $blockingIndex;
            $this->step = 'reading';
            $this->showCreateConfirmation = false;

            Notification::make()
                ->warning()
                ->title(__('Complete or skip the blocked rooms before creating invoices.'))
                ->send();

            return;
        }

        $this->creatingInvoices = true;
        $this->showCreateConfirmation = false;

        $builder = app(InvoiceBuilderService::class);
        $issueDate = Carbon::parse($this->issueDate ?: now()->toDateString());
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $invoiceIds = [];
        $failures = [];

        foreach ($this->rooms as $index => $room) {
            if (($room['skipped'] ?? false) === true) {
                $skipped++;
                continue;
            }

            try {
                $invoice = DB::transaction(function () use ($room, $builder, $issueDate) {
                    $rental = Rental::withoutGlobalScopes()->with(['unit', 'property', 'tenant'])->findOrFail($room['rental_id']);
                    $periodStart = Carbon::parse($room['period_start']);
                    $periodEnd = Carbon::parse($room['period_end']);

                    $existing = Invoice::withoutGlobalScopes()
                        ->where('rental_id', $rental->id)
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->whereDate('period_end', $periodEnd->toDateString())
                        ->first();

                    if ($existing) {
                        return null;
                    }

                    $usages = [];
                    $utilityOverrides = [];

                    foreach ($room['utilities'] as $utility) {
                        $state = (string) ($utility['state_override'] ?? 'normal');
                        if ($state !== 'normal') {
                            $utilityOverrides[$utility['property_utility_id']] = [
                                'state' => $state,
                                'reason' => $utility['override_reason'] ?? null,
                                'amount' => $utility['override_amount'] ?? null,
                                'currency' => $utility['override_currency'] ?? null,
                            ];
                        }

                        $requiresReading = (bool) ($utility['requires_reading'] ?? true);
                        $newReading = $this->parseNumber($utility['new_reading']);
                        if (in_array($state, ['not_applicable', 'skipped_this_cycle'], true)) {
                            continue;
                        }
                        if ($requiresReading && $newReading === null) {
                            continue;
                        }

                        $oldReading = $this->parseNumber($utility['old_reading']) ?? 0.0;
                        // Same max(0, new - old) as before, except a meter also
                        // applies its multiplier and unwraps a digit rollover.
                        $meter = isset($utility['meter_id'])
                            ? \App\Models\UtilityMeter::find($utility['meter_id'])
                            : null;
                        $amountUsed = $requiresReading
                            ? app(\App\Services\MeterReadingResolver::class)->consumption($oldReading, (float) $newReading, $meter)
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

                    $shouldAdvanceSchedule = true;
                    if ($this->manualMode) {
                        $currentNextInvoiceDate = $rental->next_invoice_date;
                        if ($currentNextInvoiceDate !== null) {
                            $expectedStart = Carbon::parse($currentNextInvoiceDate);
                            if ($periodStart->toDateString() !== $expectedStart->toDateString()) {
                                $shouldAdvanceSchedule = false;
                            }
                        }
                    }

                    if ($shouldAdvanceSchedule) {
                        $rental->withoutEvents(fn () => $rental->update([
                            'next_invoice_date' => $periodEnd->copy()->addDay()->startOfMonth(),
                        ]));
                    }

                    return $invoice;
                });

                if ($invoice === null) {
                    $skipped++;
                    continue;
                }

                $invoiceIds[] = $invoice->id;
                $created++;
            } catch (\Throwable $throwable) {
                report($throwable);
                $failed++;
                $failures[] = [
                    'room_number' => $room['room_number'] ?? __('Unknown room'),
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $this->resultSummary = [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'invoice_ids' => $invoiceIds,
            'failures' => $failures,
        ];
        $this->step = 'result';
        $this->creatingInvoices = false;

        Notification::make()
            ->title(__('Billing complete'))
            ->body($this->billingCompleteBody($created))
            ->success()
            ->send();
    }

    protected function billingCompleteBody(int $created): string
    {
        $counts = $this->billingStateCounts();
        $parts = [
            __('Created :count invoice(s).', ['count' => $created]),
            __('Billed :count charge(s).', ['count' => $counts['billed']]),
            __('Free/Waived :count charge(s).', ['count' => $counts['free'] + $counts['waived']]),
            __('Not applicable :count charge(s).', ['count' => $counts['not_applicable']]),
            __('Skipped this cycle :count charge(s).', ['count' => $counts['skipped_this_cycle']]),
        ];

        if ($counts['custom'] > 0) {
            $parts[] = __('Custom adjustments :count.', ['count' => $counts['custom']]);
        }

        return implode(' ', $parts);
    }

    public function startAnotherProperty(): void
    {
        $this->resetToPropertyPicker();
    }

    public function viewInvoicesUrl(): string
    {
        return InvoiceResource::getUrl('index');
    }

    public function dashboardUrl(): string
    {
        return Filament::getUrl();
    }

    public function propertySettingsUrl(): string
    {
        return PropertySettings::getUrl();
    }

    public function getAccess(): SubscriptionAccess
    {
        return SubscriptionService::effectiveAccess(auth()->user());
    }

    protected function hydrateSelectedProperty(int $propertyId): void
    {
        $this->propertyId = $propertyId;
        $this->issueDate = $this->suggestIssueDate($propertyId);
        $this->step = 'start';
        $this->rooms = [];
        $this->currentRoomIndex = 0;
        $this->reviewFocusIndex = null;
        $this->showCreateConfirmation = false;
        $this->resultSummary = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'invoice_ids' => [],
            'failures' => [],
        ];
    }

    protected function resetWizard(): void
    {
        $this->propertyId = null;
        $this->issueDate = now()->toDateString();
        $this->rooms = [];
        $this->currentRoomIndex = 0;
        $this->reviewFocusIndex = null;
        $this->showCreateConfirmation = false;
        $this->creatingInvoices = false;
        $this->resultSummary = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'invoice_ids' => [],
            'failures' => [],
        ];
    }

    protected function isBillingEnabled(?int $propertyId): bool
    {
        if (! $propertyId) {
            return false;
        }

        return (bool) PropertySetting::where('property_id', $propertyId)->value('monthly_billing_enabled');
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

        return $earliest ?? now()->toDateString();
    }

    /**
     * @return array<int>
     */
    protected function dueRentalIds(int $propertyId, Carbon $issueDate): array
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

    protected function dueRentalsQuery(int $propertyId, Carbon $issueDate): Builder
    {
        return Rental::whereIn('id', $this->dueRentalIds($propertyId, $issueDate))
            ->where('status', RentalStatus::Active->value)
            ->with(['unit', 'tenant']);
    }

    protected function loadRooms(): void
    {
        if (! $this->propertyId || ! $this->billingEnabled()) {
            $this->rooms = [];
            return;
        }

        $issueDate = Carbon::parse($this->issueDate ?: now()->toDateString());
        $utilities = $this->activeUtilities();
        $propertySetting = PropertySetting::where('property_id', $this->propertyId)->first();

        if ($this->manualMode) {
            $rentals = Rental::whereIn('id', $this->selectedRentalIds)
                ->where('property_id', $this->propertyId)
                ->where('status', RentalStatus::Active->value)
                ->with(['unit', 'tenant'])
                ->get();
        } else {
            $rentals = $this->dueRentalsQuery($this->propertyId, $issueDate)->get();
        }

        $rooms = [];

        foreach ($rentals as $rental) {
            $room = $this->buildRoomState($rental, $utilities, $propertySetting, $issueDate);
            if ($room !== null) {
                $rooms[] = $room;
            }
        }

        usort($rooms, function (array $left, array $right): int {
            $leftNumber = Str::lower((string) ($left['room_number'] ?? ''));
            $rightNumber = Str::lower((string) ($right['room_number'] ?? ''));

            return strnatcasecmp($leftNumber, $rightNumber);
        });

        $this->rooms = array_values($rooms);
    }

    protected function buildRoomState(Rental $rental, Collection $utilities, ?PropertySetting $propertySetting, Carbon $issueDate): ?array
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

        if ($periodStart->isAfter($periodEnd)) {
            if (! $this->manualMode) {
                return null;
            }
            $rent = 0.0;
        } else {
            $rent = $isFirstInvoice
                ? ProratingService::compute($propertySetting, (float) $rental->monthly_rent, $periodStart, $periodEnd)
                : (float) $rental->monthly_rent;
        }

        $readings = [];
        foreach ($utilities as $utility) {
            // Previous index comes from the room's ACTIVE meter when it has one
            // (its last reading, else its installed_reading); rooms with no meter
            // fall back to the original "latest reading row" lookup.
            $meterContext = app(\App\Services\MeterReadingResolver::class)
                ->previous((int) $rental->unit_id, (int) $utility->id);
            $latestUsage = $meterContext['usage'];

            $resolver = app(\App\Services\ChargeRuleResolver::class);
            $decision = $resolver->resolve([
                'property_utility_id' => $utility->id,
                'rental_id' => $rental->id,
                'unit_id' => $rental->unit_id,
                'date' => $periodEnd->toDateString(),
            ]);
            $propertyDecision = $resolver->resolve([
                'property_utility_id' => $utility->id,
                'property_id' => $this->propertyId,
                'date' => $periodEnd->toDateString(),
            ]);

            $readings[] = [
                'property_utility_id' => $utility->id,
                'utility_name' => $this->utilityLabel($utility->name),
                'billing_type' => $utility->billing_type->value,
                'rate' => (float) $utility->rate,
                'currency' => $utility->currency ?: 'USD',
                'unit_of_measure' => $utility->unit_of_measure,
                'requires_reading' => $utility->requiresReading(),
                'previous_usage' => (float) ($latestUsage?->amount_used ?? 0),
                'old_reading' => (string) $meterContext['previous'],
                'meter_id' => $meterContext['meter']?->getKey(),
                'meter_serial' => $meterContext['meter']?->serial,
                'new_reading' => null,
                'override_reason' => $decision['reason'],
                'state_override' => $decision['effective_state'],
                'source_scope_type' => $decision['source_scope_type'],
                'source_scope_label' => ChargeRuleResolver::scopeLabel((string) $decision['source_scope_type']),
                'property_state_override' => $propertyDecision['effective_state'],
                'property_scope_label' => ChargeRuleResolver::scopeLabel((string) $propertyDecision['source_scope_type']),
                'override_amount' => $decision['effective_state'] === 'custom' ? $decision['amount'] : null,
                'override_currency' => $decision['effective_state'] === 'custom' ? $decision['currency'] : null,
            ];
        }

        return [
            'rental_id' => $rental->id,
            'unit_id' => $rental->unit_id,
            'room_number' => $rental->unit?->room_number ?? '—',
            'occupant_name' => $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant')),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'period_display' => $periodStart->format('d M Y').' — '.$periodEnd->format('d M Y'),
            'rent' => round($rent, 2),
            'rent_currency' => $rental->monthly_rent_currency ?: 'USD',
            'is_first_invoice' => $isFirstInvoice,
            'skipped' => false,
            'skip_reason' => null,
            'utilities' => $readings,

            // Rent override state
            'rent_state_override' => 'normal',
            'rent_override_reason' => null,
            'rent_override_amount' => null,
            'rent_override_currency' => null,
        ];
    }

    protected function previewCharge(array $utility, float $amountUsed): float
    {
        $propertyUtility = PropertyUtility::find($utility['property_utility_id']);
        if (! $propertyUtility) {
            return 0.0;
        }

        $usage = new UtilityUsage([
            'amount_used' => $amountUsed,
            'is_waived' => false,
        ]);
        $usage->setRelation('propertyUtility', $propertyUtility);

        $manualParams = [];
        if (isset($utility['state_override']) && $utility['state_override'] !== 'normal') {
            $manualParams['manual_state'] = $utility['state_override'];
            $manualParams['manual_reason'] = $utility['override_reason'] ?? null;
            if (isset($utility['override_amount']) && $utility['override_amount'] !== null) {
                $manualParams['manual_amount'] = (float) $utility['override_amount'];
            }
            if (isset($utility['override_currency'])) {
                $manualParams['manual_currency'] = $utility['override_currency'];
            }
        }

        return (float) UtilityBillingService::resolveCharge($usage, $manualParams)['amount'];
    }

    protected function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    protected function formatQuantity(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return number_format($value, 0);
        }

        return number_format($value, 3, '.', '');
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

    public function firstBlockingRoomIndex(): ?int
    {
        foreach (array_keys($this->rooms) as $index) {
            if ($this->rooms[$index]['skipped'] ?? false) {
                continue;
            }
            if ($this->roomHasMissingReadings((int) $index) || 
                $this->roomHasBlockingLowReadings((int) $index) ||
                $this->roomHasInvalidPeriodOrDuplicate((int) $index)) {
                return (int) $index;
            }
        }

        return null;
    }

    public function activeRentals(): Collection
    {
        if (! $this->propertyId) {
            return collect();
        }

        return Rental::where('property_id', $this->propertyId)
            ->where('status', RentalStatus::Active->value)
            ->with(['unit', 'tenant'])
            ->get()
            ->sortBy(fn ($r) => Str::lower($r->unit?->room_number ?? ''));
    }

    public function toggleSelectAllRentals(): void
    {
        $allIds = $this->activeRentals()->pluck('id')->all();
        if (count($this->selectedRentalIds) === count($allIds)) {
            $this->selectedRentalIds = [];
        } else {
            $this->selectedRentalIds = $allIds;
        }
    }

    public function hasDuplicateInvoice(int $roomIndex): bool
    {
        $room = $this->rooms[$roomIndex] ?? null;
        if (! $room) {
            return false;
        }

        return Invoice::withoutGlobalScopes()
            ->where('rental_id', $room['rental_id'])
            ->whereDate('period_start', $room['period_start'])
            ->whereDate('period_end', $room['period_end'])
            ->exists();
    }

    public function roomHasInvalidPeriodOrDuplicate(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room) {
            return false;
        }

        if ($room['skipped'] ?? false) {
            return false;
        }

        $periodStart = Carbon::parse($room['period_start']);
        $periodEnd = Carbon::parse($room['period_end']);

        if ($periodStart->isAfter($periodEnd)) {
            return true;
        }

        if ($this->hasDuplicateInvoice($index)) {
            return true;
        }

        return false;
    }

    public function updatedRooms($value, $key): void
    {
        if (str_contains($key, 'period_start') || str_contains($key, 'period_end')) {
            $parts = explode('.', $key);
            $roomIndex = (int) $parts[0];
            $this->recalculateRoomRent($roomIndex);
        }
    }

    protected function recalculateRoomRent(int $roomIndex): void
    {
        if (! isset($this->rooms[$roomIndex])) {
            return;
        }

        $room = &$this->rooms[$roomIndex];
        $rental = Rental::withoutGlobalScopes()->find($room['rental_id']);
        if (! $rental) {
            return;
        }

        $periodStart = Carbon::parse($room['period_start']);
        $periodEnd = Carbon::parse($room['period_end']);

        if ($periodStart->isAfter($periodEnd)) {
            $room['rent'] = 0.0;
            return;
        }

        $propertySetting = PropertySetting::where('property_id', $this->propertyId)->first();
        
        $rent = $room['is_first_invoice']
            ? ProratingService::compute($propertySetting, (float) $rental->monthly_rent, $periodStart, $periodEnd)
            : (float) $rental->monthly_rent;

        $room['rent'] = round($rent, 2);
    }
}
