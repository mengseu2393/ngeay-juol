<?php

namespace App\Livewire;

use App\Models\InvoiceLine;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterUtilities;
use App\Services\UtilityReadingCorrectionService;
use App\Support\ActiveProperty;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Meter-reading history for one room, with a confirm-before-save correction flow.
 *
 * Embedded in the Rooms table slide-over and the Simple Mode popup. Editing is
 * two steps — enter the correction, then review exactly which readings change
 * (the edited one plus any later ones re-chained from it) and tick a confirm
 * box. All maths lives in UtilityReadingCorrectionService.
 */
class UnitReadingHistory extends Component
{
    private const PER_PAGE = 12;

    public int $unitId;

    public ?int $utilityId = null;

    public int $limit = self::PER_PAGE;

    public ?int $editingId = null;

    public string $newReading = '';

    public string $date = '';

    public bool $confirming = false;

    public bool $confirmed = false;

    public bool $saved = false;

    /** @var array<int, array<string, mixed>> */
    public array $changes = [];

    public function mount(int $unitId, ?int $utilityId = null): void
    {
        $this->unitId = $unitId;
        abort_unless($this->unit(), 404);
        abort_unless(auth()->user()?->can('viewAny', UtilityUsage::class), 403);
        $this->utilityId = $this->utilities()->has($utilityId) ? $utilityId : $this->utilities()->first()?->getKey();
    }

    public function selectUtility(int $id): void
    {
        abort_unless($this->utilities()->has($id), 404);
        $this->utilityId = $id;
        $this->limit = self::PER_PAGE;
        $this->cancelEdit();
    }

    public function loadMore(): void
    {
        $this->limit += self::PER_PAGE;
    }

    public function edit(int $usageId): void
    {
        $usage = $this->scopedUsage($usageId);
        abort_unless(auth()->user()?->can('update', $usage), 403);

        if (! app(UtilityReadingCorrectionService::class)->isEditable($usage)) {
            return;
        }

        $this->resetValidation();
        $this->saved = false;
        $this->editingId = $usage->getKey();
        $this->newReading = rtrim(rtrim(number_format((float) $usage->new_reading, 3, '.', ''), '0'), '.');
        $this->date = $usage->reading_date->toDateString();
        $this->confirming = false;
        $this->confirmed = false;
        $this->changes = [];
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->confirming = false;
        $this->confirmed = false;
        $this->changes = [];
        $this->resetValidation();
    }

    /** Step 1 → 2: validate, then show exactly what will change. */
    public function review(): void
    {
        $usage = $this->scopedUsage($this->editingId);
        abort_unless(auth()->user()?->can('update', $usage), 403);

        $this->validate([
            'newReading' => 'required|numeric|min:0|max:999999999',
            'date' => 'required|date|before_or_equal:today',
        ]);

        $preview = app(UtilityReadingCorrectionService::class)
            ->preview($usage, (float) $this->newReading, $this->date);

        if ($preview['error']) {
            $this->addError('newReading', $preview['error']);

            return;
        }

        $this->changes = $preview['rows'];
        $this->confirming = true;
        $this->confirmed = false;
    }

    public function backToEdit(): void
    {
        $this->confirming = false;
        $this->confirmed = false;
    }

    public function save(): void
    {
        $usage = $this->scopedUsage($this->editingId);
        abort_unless(auth()->user()?->can('update', $usage), 403);

        if (! $this->confirmed) {
            $this->addError('confirmed', __('Tick the box to confirm this change.'));

            return;
        }

        try {
            app(UtilityReadingCorrectionService::class)->apply(
                $usage,
                (float) $this->newReading,
                $this->date,
            );
        } catch (ValidationException $e) {
            $this->confirming = false;
            $this->confirmed = false;
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'new_reading' ? 'newReading' : $field, $messages[0]);
            }

            return;
        }

        $this->cancelEdit();
        $this->saved = true;
    }

    private function unit(): ?Unit
    {
        return Unit::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($this->unitId)
            ->first();
    }

    private function utilities()
    {
        $unit = $this->unit();

        return $unit
            ? MeterUtilities::forUnit($unit)->keyBy('id')
            : collect();
    }

    /** A reading of THIS room only — tampered ids from the client 404. */
    private function scopedUsage(?int $id): UtilityUsage
    {
        abort_unless($id && $this->unit(), 404);

        return UtilityUsage::query()
            ->where('unit_id', $this->unitId)
            ->where('property_utility_id', $this->utilityId)
            ->findOrFail($id);
    }

    public function render()
    {
        $utilities = $this->utilities();
        $utility = $utilities->get($this->utilityId);

        $readings = $utility
            ? UtilityUsage::query()
                ->where('unit_id', $this->unitId)
                ->where('property_utility_id', $utility->getKey())
                ->orderByDesc('reading_date')->orderByDesc('id')
                ->limit($this->limit + 1)
                ->get()
            : collect();

        $hasMore = $readings->count() > $this->limit;
        $readings = $readings->take($this->limit);

        $invoices = InvoiceLine::query()
            ->whereIn('utility_usage_id', $readings->pluck('id'))
            ->with(['invoice' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->keyBy('utility_usage_id')
            ->map->invoice;

        return view('livewire.unit-reading-history', [
            'unit' => $this->unit(),
            'utilities' => $utilities,
            'utility' => $utility,
            'readings' => $readings,
            'hasMore' => $hasMore,
            'invoices' => $invoices,
            'service' => app(UtilityReadingCorrectionService::class),
        ]);
    }
}
