<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\BillingType;
use App\Enums\InvoiceLineType;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Concerns\BuildsInvoiceForm;
use App\Models\Invoice;
use App\Models\UtilityUsage;
use App\Services\LandlordOwnershipGuard;
use App\Services\UtilityBillingService;
use App\Support\Money;
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
                    'currency' => $line->currency ?: 'USD',
                    'billing_type' => $util?->billing_type->value ?? BillingType::Metered->value,
                    'unit_of_measure' => $util?->unit_of_measure,
                    'requires_reading' => $util?->requiresReading() ?? true,
                    'is_waived' => (bool) $line->is_waived,
                    'old_reading' => (string) ($usage?->old_reading ?? 0),
                    'new_reading' => $usage?->new_reading !== null ? (string) $usage->new_reading : null,
                ];
            })
            ->values()
            ->all();

        $adhocLine = $invoice->lines->firstWhere('line_type', InvoiceLineType::AdHoc);
        if ($adhocLine) {
            $data['has_extra_charge'] = true;
            $data['extra_charge'] = (string) $adhocLine->amount;
            $data['extra_charge_currency'] = $adhocLine->currency;
            $data['extra_charge_description'] = $adhocLine->description;
        } else {
            $data['has_extra_charge'] = false;
            $data['extra_charge'] = '0';
            $data['extra_charge_currency'] = 'USD';
            $data['extra_charge_description'] = null;
        }

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
            $rate = (float) ($record->usd_khr_rate ?: ($record->property?->settings?->usd_khr_exchange_rate ?: 4000));
            $lineRate = $rate;

            if ($data['include_rent'] ?? true) {
                $rent = (float) ($data['monthly_rent'] ?? 0);
                $rentCurrency = $record->rental?->monthly_rent_currency ?: 'USD';

                $rentUsd = Money::convert($rent, $rentCurrency, 'USD', $lineRate);
                $rentKhr = Money::convert($rent, $rentCurrency, 'KHR', $lineRate);
                $rentUnitPriceUsd = Money::convert($rent, $rentCurrency, 'USD', $lineRate, 4);
                $rentUnitPriceKhr = Money::convert($rent, $rentCurrency, 'KHR', $lineRate, 4);

                $payload = [
                    'unit_price' => $rent,
                    'amount' => $rent,
                    'currency' => $rentCurrency,
                    'unit_price_currency' => $rentCurrency,
                    'amount_usd' => $rentUsd,
                    'amount_khr' => $rentKhr,
                    'unit_price_usd' => $rentUnitPriceUsd,
                    'unit_price_khr' => $rentUnitPriceKhr,
                    'exchange_rate' => $rate,
                ];

                if ($rentLine) {
                    $rentLine->update($payload);
                } else {
                    $record->lines()->create(array_merge($payload, [
                        'line_type' => InvoiceLineType::Rent,
                        'description' => 'Monthly rent',
                        'quantity' => 1,
                    ]));
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

                // readings.*.utility_usage_id is a Hidden field, so the id is
                // client-controlled: without this a landlord could overwrite
                // another landlord's meter readings through their own invoice.
                LandlordOwnershipGuard::assertOwned($usage);
                abort_unless((int) $usage->rental_id === (int) $record->rental_id, 403, __('You do not have access to that record.'));

                $requiresReading = (bool) ($row['requires_reading'] ?? true);
                $hasReading = isset($row['new_reading']) && $row['new_reading'] !== '' && $row['new_reading'] !== null;
                $old = (float) ($row['old_reading'] ?? $usage->old_reading);
                $new = $requiresReading
                    ? ($hasReading ? (float) $row['new_reading'] : $old)
                    : null;

                $usage->update([
                    'old_reading' => $old,
                    'new_reading' => $new,
                    'amount_used' => $requiresReading ? max(0, $new - $old) : 0.0,
                ]);

                $charge = UtilityBillingService::resolveCharge($usage->fresh('propertyUtility'));
                $utilityCurrency = Money::normalize($charge['currency'] ?? 'USD');
                $chargeAmount = (float) $charge['amount'];
                $chargeRate = (float) $charge['rate'];

                $chargeUsd = Money::convert($chargeAmount, $utilityCurrency, 'USD', $lineRate);
                $chargeKhr = Money::convert($chargeAmount, $utilityCurrency, 'KHR', $lineRate);
                $chargeUnitPriceUsd = Money::convert($chargeRate, $utilityCurrency, 'USD', $lineRate, 4);
                $chargeUnitPriceKhr = Money::convert($chargeRate, $utilityCurrency, 'KHR', $lineRate, 4);

                $line = $record->lines()
                    ->where('line_type', InvoiceLineType::Utility)
                    ->where('utility_usage_id', $usage->id)
                    ->first();

                $line?->update([
                    'quantity' => $charge['quantity'],
                    'unit_price' => $charge['rate'],
                    'amount' => $charge['amount'],
                    'is_waived' => $charge['is_waived'],
                    'currency' => $utilityCurrency,
                    'unit_price_currency' => $utilityCurrency,
                    'amount_usd' => $chargeUsd,
                    'amount_khr' => $chargeKhr,
                    'unit_price_usd' => $chargeUnitPriceUsd,
                    'unit_price_khr' => $chargeUnitPriceKhr,
                    'exchange_rate' => $rate,
                ]);
            }

            // 3.5 Ad-hoc line
            $adhocLine = $record->lines()->where('line_type', InvoiceLineType::AdHoc)->first();
            if ($data['has_extra_charge'] ?? false) {
                $amount = (float) ($data['extra_charge'] ?? 0);
                $adhocCurrency = $data['extra_charge_currency'] ?? 'USD';
                $desc = $data['extra_charge_description'] ?? __('Extra charge');

                $adhocUsd = Money::convert($amount, $adhocCurrency, 'USD', $lineRate);
                $adhocKhr = Money::convert($amount, $adhocCurrency, 'KHR', $lineRate);
                $adhocUnitPriceUsd = Money::convert($amount, $adhocCurrency, 'USD', $lineRate, 4);
                $adhocUnitPriceKhr = Money::convert($amount, $adhocCurrency, 'KHR', $lineRate, 4);

                $payload = [
                    'description' => $desc,
                    'amount' => $amount,
                    'unit_price' => $amount,
                    'currency' => $adhocCurrency,
                    'unit_price_currency' => $adhocCurrency,
                    'amount_usd' => $adhocUsd,
                    'amount_khr' => $adhocKhr,
                    'unit_price_usd' => $adhocUnitPriceUsd,
                    'unit_price_khr' => $adhocUnitPriceKhr,
                    'exchange_rate' => $rate,
                ];

                if ($adhocLine) {
                    $adhocLine->update($payload);
                } else {
                    $record->lines()->create(array_merge($payload, [
                        'line_type' => InvoiceLineType::AdHoc,
                        'quantity' => 1,
                    ]));
                }
            } elseif ($adhocLine) {
                $adhocLine->delete();
            }

            // 4. Re-derive amount_due + status from the rewritten lines.
            $record->refresh();
            $record->recalculateAmountDue();

            return $record;
        });
    }
}
