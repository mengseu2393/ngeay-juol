<?php

namespace App\Livewire\Concerns;

use App\Enums\RentalStatus;
use App\Livewire\Mobile\EndTenancy;
use App\Livewire\SimpleEndTenancy;
use App\Models\Unit;
use App\Services\EndTenancyService;
use App\Support\ActiveProperty;
use Illuminate\Validation\ValidationException;

/**
 * The move-out form — end date, outcome, free the room — and its submit,
 * shared by Simple Mode's end-tenancy flow ({@see SimpleEndTenancy}) and the
 * /m shell's ({@see EndTenancy}). The rules are EndTenancyService's.
 *
 * The component brings `$unitId` (?int) and `$step` (string, 'done' once the
 * tenancy has ended) itself.
 */
trait EndsTenancy
{
    public string $endDate = '';

    public string $status = '';

    public bool $freeRoom = true;

    public ?array $result = null;

    public function submit(): void
    {
        $this->validate([
            'endDate' => 'required|date',
            'status' => 'required|string',
            'unitId' => 'required|integer',
        ]);

        $unit = $this->loadUnit($this->unitId);

        if (! $unit) {
            $this->addError('unitId', __('Room not found.'));

            return;
        }

        try {
            $rental = app(EndTenancyService::class)->end(
                $unit,
                $this->endDate,
                RentalStatus::from((int) $this->status),
                $this->freeRoom,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $unit->refresh();

        $this->result = [
            'room_number' => $unit->room_number,
            'tenant_name' => $rental->occupant_name ?: ($rental->tenant?->name ?? '—'),
            'unit_status' => $unit->status->getLabel(),
            'rental_status' => RentalStatus::from((int) $this->status)->getLabel(),
        ];

        $this->step = 'done';

        $this->dispatch('tenancy-ended');
    }

    /** How an agreement can end, for the outcome select. */
    protected function endOutcomes(): array
    {
        return [
            RentalStatus::Vacated->value => RentalStatus::Vacated->getLabel(),
            RentalStatus::Expired->value => RentalStatus::Expired->getLabel(),
        ];
    }

    /** The room being vacated, scoped to the active property — null if missing/foreign. */
    protected function loadUnit(?int $id): ?Unit
    {
        if (! $id) {
            return null;
        }
        $propertyId = ActiveProperty::id();

        return Unit::query()
            ->with('activeRental.tenant')
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->whereKey($id)
            ->first();
    }
}
