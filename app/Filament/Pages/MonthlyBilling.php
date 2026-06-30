<?php

namespace App\Filament\Pages;

use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\UtilityUsage;
use App\Services\InvoiceBuilderService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Bulk monthly billing. Pick a property + period, load its occupied rooms, enter
 * only the NEW meter readings, and generate one invoice per room — rent +
 * (new − old) × utility rate — in a single run.
 */
class MonthlyBilling extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.monthly-billing';

    public ?array $data = [];

    /** Sits in the active-property group when a property is selected. */
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

    public function mount(): void
    {
        $this->form->fill([
            'property_id' => \App\Support\ActiveProperty::id(),
            'issue_date' => now()->toDateString(),
            'include_rent' => true,
            'rows' => [],
        ]);

        if (\App\Support\ActiveProperty::id()) {
            $this->loadRooms(false);
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Billing run'))
                    ->schema([
                        Forms\Components\Select::make('property_id')
                            ->label(__('Property'))
                            ->options(fn () => Property::pluck('name', 'id'))
                            ->searchable()->required()->live()
                            ->afterStateUpdated(fn () => $this->loadRooms(false))
                            // In a property context the property is implied (prefilled in mount()).
                            ->hidden(fn () => \App\Support\ActiveProperty::id() !== null),
                        Forms\Components\Toggle::make('include_rent')
                            ->label(__('Include monthly rent'))
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadRooms(false)),
                        Forms\Components\DatePicker::make('issue_date')
                            ->default(now())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadRooms(false)),
                    ])->columns(3),
 
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('load')
                        ->label(__('Load occupied rooms'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn () => $this->loadRooms(true)),
                ]),

                Forms\Components\Repeater::make('rows')
                    ->label(__('Rooms to bill'))
                    ->schema([
                        Forms\Components\Hidden::make('rental_id'),
                        Forms\Components\Hidden::make('label'),
                        Forms\Components\Hidden::make('period_start'),
                        Forms\Components\Hidden::make('period_end'),
                        Forms\Components\Toggle::make('should_bill')
                            ->label(__('Include'))
                            ->default(true)
                            ->live(),
                        Forms\Components\Placeholder::make('room_label')
                            ->label(__('Room / Occupant'))
                            ->content(fn (Get $get) => $get('label')),
                        Forms\Components\Placeholder::make('billing_period')
                            ->label(__('Billing Period'))
                            ->content(fn (Get $get) => $get('period_display') ?: '—'),
                        Forms\Components\TextInput::make('rent')
                            ->numeric()
                            ->prefix('$')
                            ->label(__('Rent'))
                            ->visible(fn (Get $get) => (bool) $get('should_bill')),
                        Forms\Components\Repeater::make('readings')
                            ->label(__('Meter readings'))
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => (bool) $get('should_bill'))
                            ->schema([
                                Forms\Components\Hidden::make('property_utility_id'),
                                Forms\Components\Hidden::make('utility_name'),
                                Forms\Components\Hidden::make('old_reading'),
                                Forms\Components\Placeholder::make('meter')
                                    ->label('')
                                    ->content(fn (Get $get) => $get('utility_name').' — '.__('old').': '.$get('old_reading')),
                                Forms\Components\TextInput::make('new_reading')
                                    ->numeric()
                                    ->label(__('New reading')),
                            ])
                            ->columns(2)
                            ->addable(false)->deletable(false)->reorderable(false),
                    ])
                    ->columns(4)
                    ->addable(false)->reorderable(false)
                    ->visible(fn (Get $get) => filled($get('rows'))),
            ])
            ->statePath('data');
    }

    /** Populate the repeater with the property's active rentals + their last readings. */
    public function loadRooms(bool $manual = false): void
    {
        $propertyId = $this->data['property_id'] ?? null;
 
        if (! $propertyId) {
            if ($manual) {
                Notification::make()->title(__('Pick a property first'))->warning()->send();
            }
 
            return;
        }
 
        $issueDate = isset($this->data['issue_date']) ? Carbon::parse($this->data['issue_date']) : now();

        // Auto-load all active metered/shared utilities for the property
        $utilities = PropertyUtility::where('property_id', $propertyId)
            ->whereIn('billing_type', [\App\Enums\BillingType::Metered->value, \App\Enums\BillingType::Shared->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
 
        $rentals = Rental::where('status', RentalStatus::Active->value)
            ->whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
            ->with(['unit', 'tenant'])
            ->get();
 
        $rows = [];
        foreach ($rentals as $rental) {
            $readings = [];
            foreach ($utilities as $utility) {
                $old = UtilityUsage::where('unit_id', $rental->unit_id)
                    ->where('property_utility_id', $utility->id)
                    ->orderByDesc('reading_date')->orderByDesc('id')
                    ->value('new_reading');
 
                $readings[] = [
                    'property_utility_id' => $utility->id,
                    'utility_name' => $utility->name,
                    'old_reading' => (string) ($old ?? 0),
                    'new_reading' => null,
                ];
            }
 
            // Auto-calculate period_start and period_end based on rental start date and past invoices
            $latestInvoice = Invoice::where('rental_id', $rental->id)
                ->orderByDesc('period_end')
                ->first();
 
            if ($latestInvoice) {
                $periodStart = Carbon::parse($latestInvoice->period_end)->addDay();
            } else {
                $periodStart = Carbon::parse($rental->start_date);
            }
 
            // The billing period end is the selected issue date
            $periodEnd = $issueDate->copy();
 
            if ($rental->end_date && $periodEnd->isAfter($rental->end_date)) {
                $periodEnd = Carbon::parse($rental->end_date);
            }
 
            // Calculate the number of days in the billing period and pro-rate the rent
            $days = 0;
            $rentAmount = 0.00;
            $shouldBill = true;

            if ($periodStart->isAfter($periodEnd)) {
                $shouldBill = false;
                $periodEnd = $periodStart->copy();
            } else {
                $days = $periodStart->diffInDays($periodEnd) + 1;
                $daysInMonth = $periodEnd->daysInMonth ?: 30;

                if ($days >= $daysInMonth) {
                    $rentAmount = (float) $rental->monthly_rent;
                } else {
                    $prorated = ($rental->monthly_rent / $daysInMonth) * $days;
                    $rentAmount = round($prorated, 2);
                }
            }
 
            $rows[] = [
                'rental_id' => $rental->id,
                'label' => ($rental->unit?->room_number ?? 'Room').' — '.($rental->occupant_name ?: ($rental->tenant?->name ?? 'tenant')),
                'rent' => (string) $rentAmount,
                'readings' => $readings,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'period_display' => $periodStart->format('d M Y').' — '.$periodEnd->format('d M Y'),
                'should_bill' => $shouldBill,
            ];
        }

        $this->data['rows'] = $rows;

        if (empty($rows)) {
            Notification::make()->title(__('No occupied rooms for this property'))->warning()->send();
        }
    }

    /** Generate one invoice per loaded room: rent + (new − old) × rate. */
    public function generate(): void
    {
        $rows = $this->data['rows'] ?? [];
        if (empty($rows)) {
            Notification::make()->title(__('Load occupied rooms first'))->warning()->send();

            return;
        }

        $issueDate = Carbon::parse($this->data['issue_date']);
        $includeRent = (bool) ($this->data['include_rent'] ?? true);

        $builder = app(InvoiceBuilderService::class);
        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! ($row['should_bill'] ?? true)) {
                continue;
            }

            $rental = Rental::find($row['rental_id'] ?? null);
            if (! $rental) {
                continue;
            }

            $periodStart = Carbon::parse($row['period_start']);
            $periodEnd = Carbon::parse($row['period_end']);

            // Align the due date to the unit's due date day if set, otherwise default to 7 days after period end.
            if ($rental->unit?->due_date) {
                $dueDay = Carbon::parse($rental->unit->due_date)->day;
                $dueDate = $periodStart->copy()->day($dueDay);
                if ($dueDate->isBefore($periodStart)) {
                    $dueDate->addMonth();
                }
            } else {
                $dueDate = $periodEnd->copy()->addDays(7);
            }

            // Skip rooms already billed for this period.
            $already = Invoice::where('rental_id', $rental->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->exists();
            if ($already) {
                $skipped++;

                continue;
            }

            $usages = [];
            foreach ($row['readings'] ?? [] as $reading) {
                if (! isset($reading['new_reading']) || $reading['new_reading'] === '' || $reading['new_reading'] === null) {
                    continue; // no reading entered this month — skip this utility
                }

                $old = (float) ($reading['old_reading'] ?? 0);
                $new = (float) $reading['new_reading'];

                $usages[] = UtilityUsage::create([
                    'property_utility_id' => $reading['property_utility_id'],
                    'unit_id' => $rental->unit_id,
                    'rental_id' => $rental->id,
                    'landlord_id' => $rental->landlord_id,
                    'recorded_by_id' => auth()->id(),
                    'reading_type' => ReadingType::Actual,
                    'reading_date' => $periodEnd,
                    'old_reading' => $old,
                    'new_reading' => $new,
                    'amount_used' => max(0, $new - $old),
                ]);
            }

            // honour an edited rent for this room (in-memory only)
            $rental->monthly_rent = (float) ($row['rent'] ?? $rental->monthly_rent);

            $builder->create([
                'rental' => $rental,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'include_rent' => $includeRent,
                'usages' => $usages,
            ]);
            $created++;
        }

        Notification::make()
            ->title(__('Billing complete'))
            ->body(__(':created invoice(s) generated', ['created' => $created]).($skipped ? ' · '.__(':skipped already billed', ['skipped' => $skipped]) : ''))
            ->success()
            ->send();

        $this->data['rows'] = [];
    }
}
