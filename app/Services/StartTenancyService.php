<?php

namespace App\Services;

use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a tenant into a room: the Rental, the room's login account and the
 * optional move-in meter readings, as one operation. Used by every screen that
 * adds an agreement so the rules live in one place.
 */
class StartTenancyService
{
    /**
     * @param  array<string, mixed>  $occupant  rental attribute => value (occupant_name, occupant_phone, ...)
     * @param  array<int|string, mixed>  $moveInReadings  [property_utility_id => reading]; blanks are skipped
     * @return array{rental: Rental, account: array}
     *
     * @throws ValidationException
     */
    public function start(Unit $unit, array $occupant, string $startDate, float $rent, float $deposit = 0, array $moveInReadings = []): array
    {
        if (Rental::query()->where('unit_id', $unit->getKey())->where('status', RentalStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['unitId' => __('This room already has an active tenancy.')]);
        }

        $readings = $this->validatedReadings($unit, $moveInReadings);

        $rental = new Rental($occupant + [
            'landlord_id' => $unit->landlord_id,
            'unit_id' => $unit->getKey(),
            'monthly_rent' => $rent,
            'monthly_rent_currency' => $unit->rent_currency ?: 'USD',
            'security_deposit' => $deposit,
            'security_deposit_currency' => $unit->rent_currency ?: 'USD',
            'status' => RentalStatus::Active,
            'start_date' => $startDate,
        ]);
        $rental->setRelation('unit', $unit);

        // Creates/resets the tenant login account (sets tenant_id and saves the rental).
        $account = app(RoomAccountService::class)->createForRental($rental);

        $this->saveReadings($unit, $rental, $readings, min($startDate, now()->toDateString()));

        return ['rental' => $rental, 'account' => $account];
    }

    /**
     * Filled-in readings for this room's metered utilities only (a tampered key
     * is dropped). A reading below the previous one is rejected — a typo that
     * would otherwise silently bill zero usage.
     *
     * @return array<int, float>
     *
     * @throws ValidationException
     */
    public function validatedReadings(Unit $unit, array $moveInReadings): array
    {
        $allowed = MeterUtilities::forUnit($unit)->keyBy('id');
        $resolver = app(MeterReadingResolver::class);
        $readings = [];

        foreach ($moveInReadings as $utilityId => $value) {
            if ($value === null || $value === '' || ! $allowed->has((int) $utilityId)) {
                continue;
            }

            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999999) {
                throw ValidationException::withMessages(["moveInReadings.$utilityId" => __('Enter a valid meter reading.')]);
            }

            $new = (float) $value;
            $previous = $resolver->previous($unit->getKey(), (int) $utilityId);

            if ($previous['source'] !== 'none' && $new < $previous['previous'] && ! $previous['meter']?->digits) {
                throw ValidationException::withMessages(["moveInReadings.$utilityId" => __('The reading cannot be lower than the previous one (:value).', [
                    'value' => MeterUtilities::trimReading($previous['previous']),
                ])]);
            }

            $readings[(int) $utilityId] = $new;
        }

        return $readings;
    }

    /**
     * Stamp the starting index for the new tenancy. amount_used stays 0: the
     * stretch since the last reading happened while the room was vacant (or
     * belongs to the previous tenant), so the new tenant must not be billed
     * for it — future readings measure from this one.
     *
     * @param  array<int, float>  $readings
     */
    private function saveReadings(Unit $unit, Rental $rental, array $readings, string $date): void
    {
        if ($readings === []) {
            return;
        }

        $resolver = app(MeterReadingResolver::class);

        DB::transaction(function () use ($readings, $unit, $rental, $date, $resolver): void {
            foreach ($readings as $utilityId => $new) {
                $baseline = $resolver->baselineFor($unit->getKey(), $utilityId, $date, $new);

                $usage = UtilityUsage::query()
                    ->where('unit_id', $unit->getKey())
                    ->where('property_utility_id', $utilityId)
                    ->whereDate('reading_date', $date)
                    ->first() ?? new UtilityUsage([
                        'unit_id' => $unit->getKey(),
                        'property_utility_id' => $utilityId,
                        'reading_date' => $date,
                    ]);

                $usage->fill([
                    'rental_id' => $rental->getKey(),
                    'reading_type' => ReadingType::Actual,
                    'old_reading' => $baseline['old'],
                    'new_reading' => $new,
                    'amount_used' => 0,
                    'recorded_by_id' => auth()->id(),
                ])->save();
            }
        });
    }
}
