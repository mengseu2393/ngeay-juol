<?php

namespace App\Livewire\Mobile;

use App\Livewire\Concerns\BuildsMonthlyBilling;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m — create this month's invoices. The same wizard as Simple Mode's
 * (Concerns\BuildsMonthlyBilling: due or hand-picked rooms, readings, prorated
 * first periods, charge rules, review, create), laid out for a phone: one room
 * card at a time with big reading fields, a sticky action bar above the tab
 * bar, the confirmation in a bottom sheet, and messages as toasts instead of
 * Filament notifications (there is no Filament here).
 */
#[Layout('mobile.layout')]
class Billing extends Component
{
    use BuildsMonthlyBilling {
        roomHasMissingReadings as protected traitRoomHasMissingReadings;
        suggestIssueDate as protected traitSuggestIssueDate;
    }

    /** No Filament notification container in /m: the shell's toast (resources/js/mobile.js). */
    protected function notify(string $title, ?string $body = null, string $level = 'warning'): void
    {
        // The body is the long "Billing complete" breakdown — the result step
        // shows it as a list (resultChargeCounts()), so the toast stays short.
        $this->dispatch('rw-m-toast', message: $title, level: $level);
    }

    /**
     * The suggestion is the raw next_invoice_date column ("2026-08-01 00:00:00"
     * on MySQL/SQLite), which an <input type="date"> rejects and shows empty.
     */
    protected function suggestIssueDate(?int $propertyId): string
    {
        return Carbon::parse($this->traitSuggestIssueDate($propertyId))->toDateString();
    }

    /**
     * The reading fields here are text inputs (so a mistyped value stays
     * visible instead of silently becoming empty, as a number input does):
     * a value that isn't a number counts as missing, never as "read".
     * Otherwise createInvoices() would bill the room without that utility.
     */
    public function roomHasMissingReadings(int $index): bool
    {
        return $this->traitRoomHasMissingReadings($index) || $this->roomHasInvalidReadings($index);
    }

    public function roomHasInvalidReadings(int $index): bool
    {
        $room = $this->rooms[$index] ?? null;
        if (! $room || ($room['skipped'] ?? false)) {
            return false;
        }

        foreach (array_keys($room['utilities']) as $utilityIndex) {
            if ($this->readingIsInvalid($index, (int) $utilityIndex)) {
                return true;
            }
        }

        return false;
    }

    /** Something was typed into a reading that is required, but it isn't a number. */
    public function readingIsInvalid(int $roomIndex, int $utilityIndex): bool
    {
        $utility = $this->rooms[$roomIndex]['utilities'][$utilityIndex] ?? null;

        if (! $utility || ! ($utility['requires_reading'] ?? true) || $this->utilityIsNotBilled($utility)) {
            return false;
        }

        $value = $utility['new_reading'] ?? null;

        return ! blank($value) && $this->parseNumber(trim((string) $value)) === null;
    }

    public function utilityIsNotBilled(array $utility): bool
    {
        return in_array((string) ($utility['state_override'] ?? 'normal'), ['not_applicable', 'skipped_this_cycle'], true);
    }

    /**
     * The room strip's dot, worked out from the wizard state alone (no queries
     * — it is drawn for every room on every keystroke): 'skipped', 'todo'
     * (readings missing), 'attention' (a lower reading with no reason) or 'done'.
     * Duplicate-invoice / bad-period checks stay on the card and the review.
     */
    public function roomStatus(int $index): string
    {
        $room = $this->rooms[$index] ?? null;

        if (! $room) {
            return 'todo';
        }

        if ($room['skipped'] ?? false) {
            return 'skipped';
        }

        if ($this->roomHasMissingReadings($index)) {
            return 'todo';
        }

        foreach ($room['utilities'] as $utility) {
            if (! ($utility['requires_reading'] ?? true) || $this->utilityIsNotBilled($utility)) {
                continue;
            }

            $new = $this->parseNumber($utility['new_reading'] ?? null);
            $old = $this->parseNumber($utility['old_reading'] ?? null) ?? 0.0;

            if ($new !== null && $new < $old && blank($utility['override_reason'] ?? null)) {
                return 'attention';
            }
        }

        return 'done';
    }

    /** Tap a room in the strip. */
    public function jumpToRoom(int $index): void
    {
        if ($this->step !== 'reading' || ! isset($this->rooms[$index])) {
            return;
        }

        $this->currentRoomIndex = $index;
    }

    /** Review → back to the readings, where the user left off. */
    public function backToReadings(): void
    {
        if ($this->rooms === []) {
            $this->step = 'start';

            return;
        }

        $this->reviewFocusIndex = null;
        $this->currentRoomIndex = min($this->currentRoomIndex, count($this->rooms) - 1);
        $this->step = 'reading';
    }

    /**
     * Result → a fresh run for the active property. (Simple Mode's "Bill
     * another property" waits for the panel sidebar's property switch; here
     * the switcher is in the top bar and reloads the screen by itself.)
     */
    public function billAgain(): void
    {
        $this->resetWizard();
        $this->manualMode = false;
        $this->selectedRentalIds = [];
        $this->refreshProperty();
    }

    /** @return array{billed: int, free: int, waived: int, not_applicable: int, skipped_this_cycle: int, custom: int} */
    public function resultChargeCounts(): array
    {
        return $this->billingStateCounts();
    }

    public function render()
    {
        return view('livewire.mobile.billing')->title(__('Create invoices'));
    }
}
