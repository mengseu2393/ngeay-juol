<?php

namespace App\Filament\Resources\InvoiceResource\Concerns;

use App\Enums\BillingType;
use App\Enums\InvoiceStatus;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;
use App\Services\MeterReadingResolver;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Carbon;

/**
 * The room → rent → meter-readings → live-total form, shared by both the Create
 * and Edit invoice pages so editing an existing invoice feels exactly like
 * creating one. The only difference is that on edit the room is locked (you
 * can't move an invoice to another tenancy) — pass $isEdit accordingly.
 */
trait BuildsInvoiceForm
{
    protected static function invoiceFormSchema(bool $isEdit = false): array
    {
        return [
            Forms\Components\Section::make(__('Room & period'))
                ->schema([
                    Forms\Components\Select::make('unit_id')
                        ->label(__('Room'))
                        ->options(fn () => static::roomOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabled($isEdit)
                        ->dehydrated(! $isEdit)
                        ->afterStateUpdated(fn ($state, Set $set, Get $get) => static::onRoomSelected($state, $set, $get))
                        ->helperText($isEdit
                            ? __('The room is fixed for an existing invoice.')
                            : __('Only occupied rooms (with an active tenant) can be billed.')),
                    Forms\Components\Hidden::make('rental_id'),
                    Forms\Components\Placeholder::make('tenant_label')
                        ->label(__('Tenant'))
                        ->content(fn (Get $get) => static::tenantLabel($get('rental_id')) ?? '—'),
                    Forms\Components\TextInput::make('monthly_rent')
                        ->label(__('Monthly rent'))
                        ->numeric()->prefix(fn (Get $get) => Money::symbolForUnitId($get('unit_id')))->required()
                        ->live(onBlur: true)
                        ->helperText(__('Auto-filled from the room — adjust if needed.')),
                    Forms\Components\Toggle::make('include_rent')
                        ->label(__('Charge rent on this invoice'))
                        ->default(true)->live(),
                    Forms\Components\Select::make('payment_status')
                        ->label(__('Status'))
                        ->options(InvoiceStatus::class)
                        ->default(InvoiceStatus::Pending)
                        ->required(),
                    Forms\Components\Hidden::make('period_start')
                        ->default(fn () => Carbon::now()->startOfMonth()),
                    Forms\Components\Hidden::make('period_end')
                        ->default(fn () => Carbon::now()->endOfMonth()),
                    Forms\Components\DatePicker::make('issue_date')
                        ->required()
                        ->default(now())
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $unitId = $get('unit_id');
                            if ($unitId) {
                                $unit = Unit::with('property.settings')->find($unitId);
                                $dueDays = $unit?->property?->settings?->invoice_due_days ?? 7;
                                if ($state) {
                                    $set('due_date', Carbon::parse($state)->addDays($dueDays)->toDateString());
                                }
                            }
                        }),
                    Forms\Components\Hidden::make('due_date')
                ])->columns(2),

            Forms\Components\Section::make(__('Utility readings'))
                ->description(__('Enter this period\'s new meter readings. Charge = (new − old) × rate.'))
                ->visible(fn (Get $get) => filled($get('unit_id')))
                ->schema([
                    Forms\Components\Repeater::make('readings')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Hidden::make('property_utility_id'),
                            Forms\Components\Hidden::make('utility_usage_id'),
                            Forms\Components\Hidden::make('utility_name'),
                            Forms\Components\Hidden::make('rate'),
                            Forms\Components\Hidden::make('billing_type'),
                            Forms\Components\Hidden::make('unit_of_measure'),
                            Forms\Components\Hidden::make('requires_reading'),
                            Forms\Components\Hidden::make('is_waived'),
                            Forms\Components\Placeholder::make('meter')
                                ->label(fn (Get $get) => $get('utility_name'))
                                ->content(fn (Get $get) => $get('requires_reading')
                                    ? static::meterHint([
                                        'rate' => $get('rate'),
                                        'unit_of_measure' => $get('unit_of_measure'),
                                    ])
                                    : __('Fixed charge').': '.Money::activeFormat((float) $get('rate'))),
                            Forms\Components\TextInput::make('old_reading')
                                ->visible(fn (Get $get) => (bool) $get('requires_reading'))
                                ->numeric()->disabled()->dehydrated()
                                ->label(__('Old')),
                            Forms\Components\TextInput::make('new_reading')
                                ->visible(fn (Get $get) => (bool) $get('requires_reading'))
                                ->numeric()->live(onBlur: true)
                                ->label(__('New reading')),
                            Forms\Components\Placeholder::make('line_amount')
                                ->label(__('Charge'))
                                ->content(fn (Get $get) => static::chargeLabel([
                                    'rate' => $get('rate'),
                                    'billing_type' => $get('billing_type'),
                                    'old_reading' => $get('old_reading'),
                                    'new_reading' => $get('new_reading'),
                                    'is_waived' => $get('is_waived'),
                                    'requires_reading' => $get('requires_reading'),
                                ])),
                        ])
                        ->columns(4)
                        ->addable(false)->deletable(false)->reorderable(false),
                ]),

            Forms\Components\Section::make(__('Total'))
                ->schema([
                    Forms\Components\Toggle::make('has_extra_charge')
                        ->label(__('Add extra charge'))
                        ->default(false)->live(),
                    Forms\Components\TextInput::make('extra_charge')
                        ->label(__('Extra charge'))
                        ->numeric()->prefix(fn (Get $get) => Money::symbolForUnitId($get('unit_id')))
                        ->default('0')->live(onBlur: true)
                        ->helperText(__('One-off extra charge to add to this invoice.'))
                        ->visible(fn (Get $get) => $get('has_extra_charge')),
                    Forms\Components\TextInput::make('extra_charge_description')
                        ->label(__('Extra charge description'))
                        ->placeholder(__('e.g. Late fee'))
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => $get('has_extra_charge')),
                    Forms\Components\Placeholder::make('grand_total')
                        ->label(__('Invoice total'))
                        ->content(fn (Get $get) => Money::format(
                            static::grandTotal([
                                'include_rent' => $get('include_rent'),
                                'monthly_rent' => $get('monthly_rent'),
                                'readings' => $get('readings'),
                                'extra_charge' => $get('extra_charge'),
                            ]),
                            Money::forUnitId($get('unit_id')),
                        )),
                    Forms\Components\Textarea::make('notes')->columnSpanFull(),
                ]),
        ];
    }

    // --- Options & reactive helpers ------------------------------------------

    /** Occupied rooms (active tenancy) in the active property, labelled by room + tenant. */
    protected static function roomOptions(): array
    {
        return Unit::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereHas('activeRental')
            ->with('activeRental.tenant')
            ->get()
            ->mapWithKeys(fn (Unit $u) => [
                $u->id => $u->room_number.' — '.($u->activeRental?->occupant_name ?: ($u->activeRental?->tenant?->name ?? __('tenant'))),
            ])
            ->all();
    }

    /** When a room is picked: fill rent + rental, and load its active-utility meters. */
    protected static function onRoomSelected($unitId, Set $set, Get $get): void
    {
        $unit = $unitId ? Unit::with(['activeRental', 'property.settings'])->find($unitId) : null;
        if (! $unit) {
            $set('rental_id', null);
            $set('readings', []);

            return;
        }

        $set('rental_id', $unit->activeRental?->id);
        $set('monthly_rent', (string) $unit->rent_amount);

        // Update due date based on property setting
        $dueDays = $unit->property?->settings?->invoice_due_days ?? 7;
        $issueDate = $get('issue_date') ? Carbon::parse($get('issue_date')) : Carbon::now();
        $set('due_date', $issueDate->copy()->addDays($dueDays)->toDateString());

        $utilities = PropertyUtility::where('property_id', $unit->property_id)
            ->where('is_active', true)->get();

        $set('readings', $utilities->map(function (PropertyUtility $util) use ($unit) {
            // Active meter's index (or its installed_reading), falling back to
            // the latest raw reading for rooms with no meter — see MeterReadingResolver.
            $old = app(MeterReadingResolver::class)->previousReading($unit->id, $util->id);

            return [
                'property_utility_id' => $util->id,
                'utility_usage_id' => null,
                'utility_name' => $util->name,
                'rate' => (string) $util->rate,
                'billing_type' => $util->billing_type->value,
                'unit_of_measure' => $util->unit_of_measure,
                'requires_reading' => $util->requiresReading(),
                'is_waived' => UtilityWaiver::isWaivedFor($util->id, $unit->activeRental?->id, $unit->id),
                'old_reading' => (string) $old,
                'new_reading' => null,
            ];
        })->all());
    }

    protected static function tenantLabel(?int $rentalId): ?string
    {
        if (! $rentalId) {
            return null;
        }
        $rental = Rental::with('tenant')->find($rentalId);

        return $rental ? ($rental->occupant_name ?: $rental->tenant?->name) : null;
    }

    protected static function meterHint(array $row): string
    {
        $rate = (float) ($row['rate'] ?? 0);
        $uom = $row['unit_of_measure'] ?? '';

        return __('Rate').': '.Money::activeFormat($rate, 2).($uom ? ' / '.$uom : '');
    }

    /** Whether this reading row is waived (truthy across bool/int/"1"/"true" shapes). */
    protected static function isWaivedRow(array $row): bool
    {
        return filter_var($row['is_waived'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Display string for the Charge column — shows "Waived" instead of $0 when waived. */
    protected static function chargeLabel(array $row): string
    {
        if (static::isWaivedRow($row)) {
            return __('Waived').' · '.Money::activeFormat(0);
        }

        return Money::activeFormat(static::rowAmount($row));
    }

    /** Charge for one reading row — mirrors UtilityBillingService::resolveCharge. */
    protected static function rowAmount(array $row): float
    {
        if (static::isWaivedRow($row)) {
            return 0.0;
        }

        if (! (bool) ($row['requires_reading'] ?? true)) {
            return round((float) ($row['rate'] ?? 0), 2);
        }

        $rate = (float) ($row['rate'] ?? 0);

        if ((int) ($row['billing_type'] ?? 0) === BillingType::Flat->value) {
            return round($rate, 2);
        }

        $new = $row['new_reading'] ?? null;
        if ($new === null || $new === '') {
            return 0.0;
        }

        return round(max(0, (float) $new - (float) ($row['old_reading'] ?? 0)) * $rate, 2);
    }

    protected static function grandTotal(array $data): float
    {
        $total = ($data['include_rent'] ?? true) ? (float) ($data['monthly_rent'] ?? 0) : 0.0;

        foreach ($data['readings'] ?? [] as $row) {
            $total += static::rowAmount($row);
        }

        $total += (float) ($data['extra_charge'] ?? 0);

        return round($total, 2);
    }
}
