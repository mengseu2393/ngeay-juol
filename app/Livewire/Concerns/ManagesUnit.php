<?php

namespace App\Livewire\Concerns;

use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Pages\UnitOverview;
use App\Filament\Resources\UnitResource;
use App\Livewire\SimpleUnitDetail;
use App\Models\InvoiceLine;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\EndTenancyService;
use App\Services\MeterReadingRecorder;
use App\Services\StartTenancyService;
use App\Services\UtilityReadingCorrectionService;
use App\Services\UtilityReadingResetService;
use App\Support\ActiveProperty;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Everything the room page does — shared by the full-mode page
 * ({@see UnitOverview}) and the Simple Mode screen
 * ({@see SimpleUnitDetail}) so the two can never drift. Rules
 * live in services; this only collects input, re-checks the policy on every
 * action, and exposes the view data.
 */
trait ManagesUnit
{
    private const PER_PAGE = 12;

    #[Locked]
    public int $unitId;

    /** Open inline panel: null | 'add' | 'end' | 'reading' | 'reset' */
    #[Locked]
    public ?string $panel = null;

    #[Locked]
    public ?int $utilityId = null;

    public int $limit = self::PER_PAGE;

    /** Flash after a successful action. */
    #[Locked]
    public ?string $notice = null;

    /** Login details shown once after moving a tenant in. */
    #[Locked]
    public ?array $credentials = null;

    // ── Add agreement ──
    public string $occupantName = '';

    public string $occupantPhone = '';

    public string $occupantIdCard = '';

    public string $startDate = '';

    public string $monthlyRent = '';

    public string $securityDeposit = '';

    /** @var array<int|string, mixed> */
    public array $moveInReadings = [];

    // ── End agreement ──
    public string $endDate = '';

    public int $outcome = 0;

    public bool $freeRoom = true;

    public bool $confirmEnd = false;

    // ── Add reading / reset ──
    public string $newReading = '';

    public string $date = '';

    public int $type = 1;

    public string $startingReading = '0';

    public bool $confirmReset = false;

    // ── Correct a past reading (two steps: edit → review & confirm) ──
    #[Locked]
    public ?int $editingId = null;

    public string $editReading = '';

    public string $editDate = '';

    public bool $confirming = false;

    public bool $confirmedEdit = false;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $changes = [];

    public function mountUnit(int|string $unit): void
    {
        $model = Unit::query()->findOrFail($unit);
        abort_unless(Auth::user()?->can('view', $model), 403);

        $this->unitId = $model->getKey();
        $this->utilityId = UnitResource::meterUtilitiesFor($model)->first()?->getKey();

        // The rest of the panel (switcher, sidebar group) follows the room's property.
        ActiveProperty::set($model->property_id);

        $this->resetForms($model);
    }

    // ---------------------------------------------------------------------
    // Panels
    // ---------------------------------------------------------------------

    public function openPanel(string $panel): void
    {
        $unit = $this->unit();
        $user = Auth::user();

        match ($panel) {
            'add' => abort_unless($unit->status === UnitStatus::Available && ! $unit->activeRental && $user->can('create', Rental::class), 403),
            'end' => abort_unless($unit->activeRental && $user->can('update', $unit->activeRental), 403),
            'reading', 'reset' => abort_unless($user->can('create', UtilityUsage::class), 403),
            default => abort(404),
        };

        $this->resetForms($unit);
        $this->cancelEdit();
        $this->notice = null;
        $this->credentials = null;
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->resetValidation();
    }

    public function selectUtility(int $id): void
    {
        abort_unless(UnitResource::meterUtilitiesFor($this->unit())->contains('id', $id), 404);
        $this->utilityId = $id;
        $this->limit = self::PER_PAGE;
        $this->cancelEdit();
        $this->closePanel();
    }

    public function loadMore(): void
    {
        $this->limit += self::PER_PAGE;
    }

    // ---------------------------------------------------------------------
    // Agreement
    // ---------------------------------------------------------------------

    public function startAgreement(): void
    {
        $unit = $this->unit();
        abort_unless($this->panel === 'add' && Auth::user()?->can('create', Rental::class), 403);

        $this->validate([
            'occupantName' => 'required|string|max:255',
            'occupantPhone' => 'nullable|string|max:50',
            'occupantIdCard' => 'nullable|string|max:255',
            'startDate' => 'required|date',
            'monthlyRent' => 'required|numeric|min:0',
            'securityDeposit' => 'nullable|numeric|min:0',
            'moveInReadings.*' => 'nullable|numeric|min:0|max:999999999',
        ]);

        try {
            $started = app(StartTenancyService::class)->start(
                $unit,
                [
                    'occupant_name' => trim($this->occupantName),
                    'occupant_phone' => trim($this->occupantPhone) ?: null,
                    'occupant_id_card' => trim($this->occupantIdCard) ?: null,
                ],
                $this->startDate,
                (float) $this->monthlyRent,
                $this->securityDeposit !== '' ? (float) $this->securityDeposit : 0,
                $this->moveInReadings,
            );
        } catch (ValidationException $e) {
            $this->copyErrors($e);

            return;
        }

        $this->credentials = [
            'username' => $started['account']['username'],
            'password' => $started['account']['password'] ?? null,
        ];
        $this->notice = __('Agreement created.');
        $this->panel = null;

        // Other screens of the same page (room list, tenants, ...) stay mounted.
        $this->dispatch('tenancy-started');
    }

    public function endAgreement(): void
    {
        $this->resetValidation();
        $unit = $this->unit();
        abort_unless($this->panel === 'end' && $unit->activeRental && Auth::user()?->can('update', $unit->activeRental), 403);

        $this->validate([
            'endDate' => 'required|date',
            'outcome' => 'required|in:'.RentalStatus::Vacated->value.','.RentalStatus::Expired->value,
        ]);

        if (! $this->confirmEnd) {
            $this->addError('confirmEnd', __('Tick the box to confirm this change.'));

            return;
        }

        try {
            app(EndTenancyService::class)->end($unit, $this->endDate, RentalStatus::from($this->outcome), $this->freeRoom);
        } catch (ValidationException $e) {
            $this->copyErrors($e);

            return;
        }

        $this->notice = __('Agreement ended.');
        $this->panel = null;

        $this->dispatch('tenancy-ended');
    }

    // ---------------------------------------------------------------------
    // Readings
    // ---------------------------------------------------------------------

    public function saveReading(): void
    {
        $unit = $this->unit();
        abort_unless($this->panel === 'reading' && Auth::user()?->can('create', UtilityUsage::class), 403);
        abort_unless(UnitResource::meterUtilitiesFor($unit)->contains('id', $this->utilityId), 404);

        $this->validate([
            'newReading' => 'required|numeric|min:0|max:999999999',
            'date' => 'required|date|before_or_equal:today',
            'type' => 'required|in:'.ReadingType::Actual->value.','.ReadingType::Estimated->value,
        ]);

        try {
            app(MeterReadingRecorder::class)->record($unit, $this->utilityId, (float) $this->newReading, $this->date, ReadingType::from($this->type));
        } catch (ValidationException $e) {
            $this->copyErrors($e);

            return;
        }

        $this->notice = __('Meter reading saved.');
        $this->panel = null;
    }

    public function resetReading(): void
    {
        $this->resetValidation();
        $unit = $this->unit();
        abort_unless($this->panel === 'reset' && Auth::user()?->can('create', UtilityUsage::class), 403);
        abort_unless(UnitResource::meterUtilitiesFor($unit)->contains('id', $this->utilityId), 404);

        $this->validate([
            'startingReading' => 'required|numeric|min:0|max:999999999',
            'date' => 'required|date|before_or_equal:today',
        ]);

        if (! $this->confirmReset) {
            $this->addError('confirmReset', __('Tick the box to confirm this change.'));

            return;
        }

        try {
            app(UtilityReadingResetService::class)->reset($unit, $this->utilityId, (float) $this->startingReading, $this->date);
        } catch (ValidationException $e) {
            $this->copyErrors($e);

            return;
        }

        $this->notice = __('Reading reset. The next reading will be measured from the new starting point.');
        $this->panel = null;
    }

    public function edit(int $usageId): void
    {
        $usage = $this->usage($usageId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        if (! app(UtilityReadingCorrectionService::class)->isEditable($usage)) {
            return;
        }

        $this->closePanel();
        $this->notice = null;
        $this->editingId = $usage->getKey();
        $this->editReading = rtrim(rtrim(number_format((float) $usage->new_reading, 3, '.', ''), '0'), '.');
        $this->editDate = $usage->reading_date->toDateString();
        $this->confirming = false;
        $this->confirmedEdit = false;
        $this->changes = [];
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->confirming = false;
        $this->confirmedEdit = false;
        $this->changes = [];
    }

    public function reviewEdit(): void
    {
        $usage = $this->usage($this->editingId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        $this->validate([
            'editReading' => 'required|numeric|min:0|max:999999999',
            'editDate' => 'required|date|before_or_equal:today',
        ]);

        $preview = app(UtilityReadingCorrectionService::class)->preview($usage, (float) $this->editReading, $this->editDate);

        if ($preview['error']) {
            $this->addError('editReading', $preview['error']);

            return;
        }

        $this->changes = $preview['rows'];
        $this->confirming = true;
        $this->confirmedEdit = false;
    }

    public function backToEdit(): void
    {
        $this->confirming = false;
        $this->confirmedEdit = false;
    }

    public function saveEdit(): void
    {
        $this->resetValidation();
        $usage = $this->usage($this->editingId);
        abort_unless(Auth::user()?->can('update', $usage), 403);

        if (! $this->confirmedEdit) {
            $this->addError('confirmedEdit', __('Tick the box to confirm this change.'));

            return;
        }

        try {
            app(UtilityReadingCorrectionService::class)->apply($usage, (float) $this->editReading, $this->editDate);
        } catch (ValidationException $e) {
            $this->confirming = false;
            $this->confirmedEdit = false;
            $this->addError('editReading', collect($e->errors())->flatten()->first());

            return;
        }

        $this->cancelEdit();
        $this->notice = __('Reading corrected.');
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    protected function unit(): Unit
    {
        return Unit::query()->with(['property', 'activeRental.tenant'])->findOrFail($this->unitId);
    }

    /** A reading of THIS room and the selected utility only — a tampered id 404s. */
    private function usage(?int $id): UtilityUsage
    {
        abort_unless($id, 404);

        return UtilityUsage::query()
            ->where('unit_id', $this->unitId)
            ->where('property_utility_id', $this->utilityId)
            ->findOrFail($id);
    }

    private function resetForms(Unit $unit): void
    {
        $this->resetValidation();
        $this->occupantName = $this->occupantPhone = $this->occupantIdCard = $this->securityDeposit = '';
        $this->startDate = now()->toDateString();
        $this->monthlyRent = (string) ($unit->rent_amount + 0);
        $this->moveInReadings = [];
        $this->endDate = now()->toDateString();
        $this->outcome = RentalStatus::Vacated->value;
        $this->freeRoom = true;
        $this->confirmEnd = false;
        $this->newReading = '';
        $this->date = now()->toDateString();
        $this->type = ReadingType::Actual->value;
        $this->startingReading = '0';
        $this->confirmReset = false;
    }

    private function copyErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
    }

    /** @return array<string, mixed> */
    protected function unitViewData(): array
    {
        $unit = $this->unit();
        $utilities = UnitResource::meterUtilitiesFor($unit)->keyBy('id');
        $utility = $utilities->get($this->utilityId);

        $readings = $utility
            ? UtilityUsage::query()
                ->where('unit_id', $unit->getKey())
                ->where('property_utility_id', $utility->getKey())
                ->orderByDesc('reading_date')->orderByDesc('id')
                ->limit($this->limit + 1)->get()
            : collect();

        $hasMore = $readings->count() > $this->limit;
        $readings = $readings->take($this->limit);

        $invoices = InvoiceLine::query()
            ->whereIn('utility_usage_id', $readings->pluck('id'))
            ->with(['invoice' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()->keyBy('utility_usage_id')->map->invoice;

        $user = Auth::user();

        return [
            'unit' => $unit,
            'rental' => $unit->activeRental,
            'utilities' => $utilities,
            'utility' => $utility,
            'readings' => $readings,
            'hasMore' => $hasMore,
            'invoices' => $invoices,
            'service' => app(UtilityReadingCorrectionService::class),
            'can' => [
                'add' => $unit->status === UnitStatus::Available && ! $unit->activeRental && $user->can('create', Rental::class),
                'end' => $unit->activeRental && $user->can('update', $unit->activeRental),
                'readings' => $user->can('create', UtilityUsage::class),
                'editRoom' => $user->can('update', $unit),
            ],
            'outcomes' => [
                RentalStatus::Vacated->value => RentalStatus::Vacated->getLabel(),
                RentalStatus::Expired->value => RentalStatus::Expired->getLabel(),
            ],
            'editingUsage' => $this->editingId ? $readings->firstWhere('id', $this->editingId) : null,
        ];
    }
}
