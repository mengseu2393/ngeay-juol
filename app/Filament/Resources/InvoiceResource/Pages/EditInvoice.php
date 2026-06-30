<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\BillingType;
use App\Enums\InvoiceLineType;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Concerns\BuildsInvoiceForm;
use App\Models\Invoice;
use App\Models\UtilityUsage;
use App\Services\UtilityBillingService;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Editing mirrors the Create experience: the same room → rent → readings → live
 * total form. The room is locked (an invoice can't move tenancies), but the rent,
 * meter readings, period, status and notes are all editable — and on save we
 * write the readings back to their UtilityUsage rows and re-price the lines.
 */
class EditInvoice extends EditRecord
{
    use BuildsInvoiceForm;

    protected static string $resource = InvoiceResource::class;

    public function form(Form $form): Form
    {
        return $form->schema(static::invoiceFormSchema(isEdit: true));
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceResource::pageDocumentActions(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Hydrate the Create-style form from the persisted invoice: its rent line,
     * its utility lines (→ their meter readings) and the period fields.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->record->load(['rental', 'lines.utilityUsage.propertyUtility']);

        $rentLine = $invoice->lines->firstWhere('line_type', InvoiceLineType::Rent);

        $data['unit_id'] = $invoice->rental?->unit_id;
        $data['rental_id'] = $invoice->rental_id;
        $data['include_rent'] = $rentLine !== null;
        $data['monthly_rent'] = (string) ($rentLine?->amount ?? $invoice->rental?->monthly_rent ?? 0);

        $data['readings'] = $invoice->lines
            ->where('line_type', InvoiceLineType::Utility)
            ->map(function ($line) {
                $usage = $line->utilityUsage;
                $util = $usage?->propertyUtility;

                return [
                    'property_utility_id' => $usage?->property_utility_id,
                    'utility_usage_id' => $usage?->id,
                    'utility_name' => $util?->name ?? $line->description,
                    'rate' => (string) ($util?->rate ?? $line->unit_price),
                    'billing_type' => $util?->billing_type->value ?? BillingType::Metered->value,
                    'unit_of_measure' => $util?->unit_of_measure,
                    'is_waived' => (bool) $line->is_waived,
                    'old_reading' => (string) ($usage?->old_reading ?? 0),
                    'new_reading' => $usage?->new_reading !== null ? (string) $usage->new_reading : null,
                ];
            })
            ->values()
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        return DB::transaction(function () use ($record, $data) {
            // 1. Scalar invoice fields (status set first so Draft/Cancelled stay sticky).
            $record->fill([
                'payment_status' => $data['payment_status'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
            ])->save();

            // 2. Rent line — upsert or remove to match the "charge rent" toggle.
            $rentLine = $record->lines()->where('line_type', InvoiceLineType::Rent)->first();
            if ($data['include_rent'] ?? true) {
                $rent = (float) ($data['monthly_rent'] ?? 0);
                if ($rentLine) {
                    $rentLine->update(['unit_price' => $rent, 'amount' => $rent]);
                } else {
                    $record->lines()->create([
                        'line_type' => InvoiceLineType::Rent,
                        'description' => 'Monthly rent',
                        'quantity' => 1,
                        'unit_price' => $rent,
                        'amount' => $rent,
                    ]);
                }
            } elseif ($rentLine) {
                $rentLine->delete();
            }

            // 3. Readings → write back to UtilityUsage and re-price its line.
            foreach ($data['readings'] ?? [] as $row) {
                $usageId = $row['utility_usage_id'] ?? null;
                if (! $usageId) {
                    continue; // only rows already attached to this invoice are editable here
                }

                $usage = UtilityUsage::withoutGlobalScopes()->find($usageId);
                if (! $usage) {
                    continue;
                }

                $hasReading = isset($row['new_reading']) && $row['new_reading'] !== '' && $row['new_reading'] !== null;
                $old = (float) ($row['old_reading'] ?? $usage->old_reading);
                $new = $hasReading ? (float) $row['new_reading'] : $old;

                $usage->update([
                    'old_reading' => $old,
                    'new_reading' => $new,
                    'amount_used' => max(0, $new - $old),
                ]);

                $charge = UtilityBillingService::resolveCharge($usage->fresh('propertyUtility'));

                $line = $record->lines()
                    ->where('line_type', InvoiceLineType::Utility)
                    ->where('utility_usage_id', $usage->id)
                    ->first();

                $line?->update([
                    'quantity' => $charge['quantity'],
                    'unit_price' => $charge['rate'],
                    'amount' => $charge['amount'],
                    'is_waived' => $charge['is_waived'],
                ]);
            }

            // 4. Re-derive amount_due + status from the rewritten lines.
            $record->refresh();
            $record->recalculateAmountDue();

            return $record;
        });
    }
}
