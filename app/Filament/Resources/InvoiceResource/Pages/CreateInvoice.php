<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\BillingType;
use App\Enums\InvoiceStatus;
use App\Enums\ReadingType;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Concerns\BuildsInvoiceForm;
use App\Models\Rental;
use App\Models\UtilityUsage;
use App\Services\InvoiceBuilderService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Room-based invoice creation: pick a room → its rent auto-fills and its meters
 * load → enter the new readings → the total (rent + Σ utility usage) is computed
 * live, and on save we persist the readings + the invoice in one transaction
 * through {@see InvoiceBuilderService}.
 */
class CreateInvoice extends CreateRecord
{
    use BuildsInvoiceForm;

    protected static string $resource = InvoiceResource::class;

    public function form(Form $form): Form
    {
        return $form->schema(static::invoiceFormSchema(isEdit: false));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $rental = Rental::withoutGlobalScopes()->find($data['rental_id'] ?? null);

        if (! $rental) {
            Notification::make()->title(__('That room has no active tenant to bill.'))->danger()->send();
            $this->halt();
        }

        $periodEnd = Carbon::parse($data['period_end']);

        // Persist each entered reading as a UtilityUsage (flat utilities bill always).
        $usages = [];
        foreach ($data['readings'] ?? [] as $row) {
            $isFlat = (int) ($row['billing_type'] ?? 0) === BillingType::Flat->value;
            $hasReading = isset($row['new_reading']) && $row['new_reading'] !== '' && $row['new_reading'] !== null;
            if (! $isFlat && ! $hasReading) {
                continue; // metered utility with no reading this period — skip
            }

            $old = (float) ($row['old_reading'] ?? 0);
            $new = $isFlat ? null : ($hasReading ? (float) $row['new_reading'] : $old);

            $usages[] = UtilityUsage::create([
                'property_utility_id' => $row['property_utility_id'],
                'unit_id' => $rental->unit_id,
                'rental_id' => $rental->id,
                'landlord_id' => $rental->landlord_id,
                'recorded_by_id' => auth()->id(),
                'reading_type' => ReadingType::Actual,
                'reading_date' => $periodEnd,
                'old_reading' => $old,
                'new_reading' => $new,
                'amount_used' => $isFlat ? 0.0 : max(0, $new - $old),
            ]);
        }

        // Ad-hoc extra charge (single-line). Submitted as an adhoc line to the
        // InvoiceBuilderService so it becomes an InvoiceLine and contributes
        // to amount_due.
        $adhoc = [];
        if (isset($data['extra_charge']) && (float) $data['extra_charge'] > 0) {
            $adhoc[] = [
                'description' => $data['extra_charge_description'] ?? __('Extra charge'),
                'amount' => (float) $data['extra_charge'],
            ];
        }

        // Honour an adjusted rent for this invoice (in-memory only).
        $rental->monthly_rent = (float) ($data['monthly_rent'] ?? $rental->monthly_rent);

        return app(InvoiceBuilderService::class)->create([
            'rental' => $rental,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'include_rent' => (bool) ($data['include_rent'] ?? true),
            'status' => $data['payment_status'] ?? InvoiceStatus::Pending,
            'usages' => $usages,
            'adhoc' => $adhoc,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
