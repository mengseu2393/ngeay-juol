<?php

namespace App\Filament\Pages;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\UtilityUsage;
use App\Services\InvoiceBuilderService;
use App\Services\ProratingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Monthly billing — zero-click flow:
 *
 * The page opens with every due room already loaded and ready.
 * The landlord only needs to enter new meter readings then press Generate.
 *
 * A room is "due" when its next_invoice_date ≤ today (or has never been set).
 * After each successful run next_invoice_date rolls forward one month automatically.
 */
class MonthlyBilling extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.monthly-billing';

    // ── Filament form state ───────────────────────────────────────────────
    public ?array $data = [];

    /** Whether monthly billing is enabled for the current property. */
    public bool $billingEnabled = false;

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Navigation badge: count of active rentals whose next_invoice_date is
     * today or overdue — i.e. rooms waiting to be billed.
     */
    public static function getNavigationBadge(): ?string
    {
        $landlordId = auth()->user()?->effectiveLandlordId();
        if (! $landlordId) {
            return null;
        }

        // Only count properties that have actually enabled the feature.
        $count = Rental::where('status', RentalStatus::Active->value)
            ->where('landlord_id', $landlordId)
            ->whereHas('unit.property.setting', fn ($q) => $q->where('monthly_billing_enabled', true))
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
        return \App\Support\ActiveProperty::id() !== null
            ? \App\Support\ActiveProperty::NAV_GROUP
            : 'Billing';
    }

    public static function getNavigationLabel(): string
    {
        return __('Monthly billing');
    }

    public function getTitle(): string
    {
        return __('Monthly billing');
    }

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->can('create_invoice'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess()
            && (\App\Support\ActiveProperty::id() !== null || (bool) auth()->user()?->isPlatformStaff());
    }

    // ─────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $propertyId = \App\Support\ActiveProperty::id();

        $this->billingEnabled = $this->isEnabledForProperty($propertyId);

        $this->form->fill([
            'property_id'  => $propertyId,
            'issue_date'   => $this->suggestIssueDate($propertyId),
            'include_rent' => true,
            'rows'         => [],
        ]);

        if ($propertyId && $this->billingEnabled) {
            $this->refreshRows();
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Check whether this feature is switched on for the given property.
     */
    protected function isEnabledForProperty(?int $propertyId): bool
    {
        if (! $propertyId) {
            return false;
        }

        return (bool) \App\Models\PropertySetting::where('property_id', $propertyId)
            ->value('monthly_billing_enabled');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * The earliest next_invoice_date among the property's active, due rentals —
     * falls back to today when nothing is set.
     */
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
     * IDs of all active rentals for the current property that are due on or
     * before the selected issue_date.
     *
     * A rental with no next_invoice_date has never been billed — it is always due.
     */
    protected function dueRentalIds(int $propertyId, Carbon $issueDate): array
    {
        return Rental::where('property_id', $propertyId)
            ->where('status', RentalStatus::Active->value)
            ->where(function ($q) use ($issueDate) {
                $q->whereNull('next_invoice_date')
                  ->orWhereDate('next_invoice_date', '<=', $issueDate->toDateString());
            })
            ->pluck('id')
            ->all();
    }

    // ─── Form ─────────────────────────────────────────────────────────────

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // ── Header controls ───────────────────────────────────────
                Forms\Components\Section::make(__('Billing run'))
                    ->schema([
                        // Property selector — hidden when a property is already active.
                        Forms\Components\Select::make('property_id')
                            ->label(__('Property'))
                            ->options(fn () => Property::pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function () {
                                $this->data['rows'] = [];
                                $pid = $this->data['property_id'] ?? null;
                                $this->billingEnabled = $this->isEnabledForProperty($pid ? (int) $pid : null);
                                $this->data['issue_date'] = $this->suggestIssueDate($pid ? (int) $pid : null);
                                $this->refreshRows();
                            })
                            ->hidden(fn () => \App\Support\ActiveProperty::id() !== null),

                        Forms\Components\DatePicker::make('issue_date')
                            ->label(__('Issue date'))
                            ->default(now())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshRows()),

                        Forms\Components\Toggle::make('include_rent')
                            ->label(__('Include monthly rent'))
                            ->default(true)
                            ->live(),
                    ])
                    ->columns(3),

                // ── Due-room readings — auto-loaded, no selection needed ──
                Forms\Components\Repeater::make('rows')
                    ->label(__('Rooms due for billing'))
                    ->schema([
                        Forms\Components\Hidden::make('rental_id'),
                        Forms\Components\Hidden::make('period_start'),
                        Forms\Components\Hidden::make('period_end'),
                        Forms\Components\Hidden::make('label'),
                        Forms\Components\Hidden::make('period_display'),
                        Forms\Components\Hidden::make('is_first_invoice'),

                        Forms\Components\Placeholder::make('room_label')
                            ->label(__('Room / Occupant'))
                            ->content(fn (Get $get) => $get('label'))
                            ->columnSpan(2),

                        Forms\Components\Placeholder::make('billing_period')
                            ->label(__('Billing Period'))
                            ->content(fn (Get $get) => $get('period_display') ?: '—')
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('rent')
                            ->numeric()
                            ->prefix('$')
                            ->label(__('Rent'))
                            ->visible(fn (Get $get) => (bool) ($this->data['include_rent'] ?? true))
                            ->columnSpan(2),

                        Forms\Components\Repeater::make('readings')
                            ->label(__('Meter readings'))
                            ->columnSpanFull()
                            ->schema([
                                Forms\Components\Hidden::make('property_utility_id'),
                                Forms\Components\Hidden::make('utility_name'),
                                Forms\Components\Hidden::make('old_reading'),
                                Forms\Components\Placeholder::make('meter')
                                    ->label(__('Utility'))
                                    ->content(fn (Get $get) => $get('utility_name')),
                                Forms\Components\Placeholder::make('prev')
                                    ->label(__('Previous reading'))
                                    ->content(fn (Get $get) => $get('old_reading') ?? '—'),
                                Forms\Components\TextInput::make('new_reading')
                                    ->label(__('New reading'))
                                    ->numeric()
                                    ->placeholder(__('Enter value')),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->columns(4)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->visible(fn (Get $get) => filled($get('rows'))),
            ])
            ->statePath('data');
    }

    // ─── Data loader ──────────────────────────────────────────────────────

    /**
     * Build the rows array from all rentals that are due on or before issue_date.
     * Called on mount and whenever property_id or issue_date changes.
     */
    public function refreshRows(): void
    {
        $propertyId = $this->data['property_id'] ?? \App\Support\ActiveProperty::id();

        if (! $propertyId || ! $this->billingEnabled) {
            $this->data['rows'] = [];

            return;
        }

        $issueDate = isset($this->data['issue_date'])
            ? Carbon::parse($this->data['issue_date'])
            : now();

        $dueIds = $this->dueRentalIds((int) $propertyId, $issueDate);

        if (empty($dueIds)) {
            $this->data['rows'] = [];

            return;
        }

        $utilities = PropertyUtility::where('property_id', $propertyId)
            ->whereIn('billing_type', [BillingType::Metered->value, BillingType::Shared->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rentals = Rental::whereIn('id', $dueIds)
            ->where('status', RentalStatus::Active->value)
            ->with(['unit', 'tenant'])
            ->get();

        $propertySetting = PropertySetting::where('property_id', $propertyId)->first();

        $rows = [];
        foreach ($rentals as $rental) {
            $readings = [];
            foreach ($utilities as $utility) {
                $old = UtilityUsage::where('unit_id', $rental->unit_id)
                    ->where('property_utility_id', $utility->id)
                    ->orderByDesc('reading_date')
                    ->orderByDesc('id')
                    ->value('new_reading');

                $readings[] = [
                    'property_utility_id' => $utility->id,
                    'utility_name'        => $utility->name,
                    'old_reading'         => (string) ($old ?? 0),
                    'new_reading'         => null,
                ];
            }

            $latestInvoice  = Invoice::where('rental_id', $rental->id)->orderByDesc('period_end')->first();
            $isFirstInvoice = $latestInvoice === null;

            $periodStart = $isFirstInvoice
                ? Carbon::parse($rental->start_date)
                : Carbon::parse($latestInvoice->period_end)->addDay();

            $periodEnd = $issueDate->copy();
            if ($rental->end_date && $periodEnd->isAfter($rental->end_date)) {
                $periodEnd = Carbon::parse($rental->end_date);
            }

            if ($periodStart->isAfter($periodEnd)) {
                continue; // already billed — skip silently
            }

            $rentAmount = $isFirstInvoice
                ? ProratingService::compute($propertySetting, (float) $rental->monthly_rent, $periodStart, $periodEnd)
                : (float) $rental->monthly_rent;

            $depositHint = '';
            if ($isFirstInvoice) {
                $depositAmount = ProratingService::depositAmount($propertySetting, (float) $rental->monthly_rent);
                if ($depositAmount > 0) {
                    $depositHint = ' + $' . number_format($depositAmount, 2) . ' deposit';
                }
            }

            $rows[] = [
                'rental_id'        => $rental->id,
                'is_first_invoice' => $isFirstInvoice,
                'label'            => ($rental->unit?->room_number ?? 'Room')
                    . ' — '
                    . ($rental->occupant_name ?: ($rental->tenant?->name ?? 'tenant'))
                    . ($isFirstInvoice ? ' [First invoice' . $depositHint . ']' : ''),
                'rent'             => (string) $rentAmount,
                'readings'         => $readings,
                'period_start'     => $periodStart->toDateString(),
                'period_end'       => $periodEnd->toDateString(),
                'period_display'   => $periodStart->format('d M Y') . ' — ' . $periodEnd->format('d M Y'),
            ];
        }

        $this->data['rows'] = $rows;
    }

    // ─── Generate ─────────────────────────────────────────────────────────

    public function generate(): void
    {
        $rows = $this->data['rows'] ?? [];

        if (empty($rows)) {
            Notification::make()
                ->title(__('No rooms due for billing on this date.'))
                ->warning()
                ->send();

            return;
        }

        $issueDate   = Carbon::parse($this->data['issue_date']);
        $includeRent = (bool) ($this->data['include_rent'] ?? true);
        $builder     = app(InvoiceBuilderService::class);
        $created     = 0;
        $skipped     = 0;

        foreach ($rows as $row) {
            $rental = Rental::find($row['rental_id'] ?? null);
            if (! $rental) {
                continue;
            }

            $periodStart = Carbon::parse($row['period_start']);
            $periodEnd   = Carbon::parse($row['period_end']);

            $already = Invoice::where('rental_id', $rental->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->exists();

            if ($already) {
                $skipped++;

                continue;
            }

            if ($rental->unit?->due_date) {
                $dueDay  = Carbon::parse($rental->unit->due_date)->day;
                $dueDate = $periodStart->copy()->day($dueDay);
                if ($dueDate->isBefore($periodStart)) {
                    $dueDate->addMonth();
                }
            } else {
                $dueDate = $periodEnd->copy()->addDays(7);
            }

            $usages = [];
            foreach ($row['readings'] ?? [] as $reading) {
                if (! isset($reading['new_reading']) || $reading['new_reading'] === '' || $reading['new_reading'] === null) {
                    continue;
                }

                $old      = (float) ($reading['old_reading'] ?? 0);
                $new      = (float) $reading['new_reading'];
                $usages[] = UtilityUsage::create([
                    'property_utility_id' => $reading['property_utility_id'],
                    'unit_id'             => $rental->unit_id,
                    'rental_id'           => $rental->id,
                    'landlord_id'         => $rental->landlord_id,
                    'recorded_by_id'      => auth()->id(),
                    'reading_type'        => ReadingType::Actual,
                    'reading_date'        => $periodEnd,
                    'old_reading'         => $old,
                    'new_reading'         => $new,
                    'amount_used'         => max(0, $new - $old),
                ]);
            }

            $rental->monthly_rent = (float) ($row['rent'] ?? $rental->monthly_rent);

            $builder->create([
                'rental'           => $rental,
                'period_start'     => $periodStart,
                'period_end'       => $periodEnd,
                'issue_date'       => $issueDate,
                'due_date'         => $dueDate,
                'include_rent'     => $includeRent,
                'is_first_invoice' => (bool) ($row['is_first_invoice'] ?? false),
                'usages'           => $usages,
            ]);

            // Roll next_invoice_date forward so the next run pre-fills the right date.
            $rental->withoutEvents(fn () => $rental->update([
                'next_invoice_date' => $periodEnd->copy()->addDay()->startOfMonth(),
            ]));

            $created++;
        }

        Notification::make()
            ->title(__('Billing complete'))
            ->body(
                __(':created invoice(s) generated', ['created' => $created])
                . ($skipped ? ' · ' . __(':skipped already billed', ['skipped' => $skipped]) : '')
            )
            ->success()
            ->send();

        // Reload — the rows will be empty if everything was billed.
        $this->data['rows'] = [];
        $this->refreshRows();
    }
}
