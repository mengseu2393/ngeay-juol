<?php

namespace App\Console\Commands;

use App\Enums\RentalStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\InvoiceBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRentInvoices extends Command
{
    protected $signature = 'invoices:generate-rent {--date= : Any date within the billing month (defaults to today)}';

    protected $description = 'Generate monthly rent invoices for all active rentals (idempotent per rental/period).';

    public function handle(InvoiceBuilderService $builder): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();
        $periodStart = $date->copy()->startOfMonth();
        $periodEnd = $date->copy()->endOfMonth();

        $created = 0;
        $skipped = 0;

        Rental::withoutGlobalScopes()
            ->where('status', RentalStatus::Active->value)
            ->with('unit')                              // eager-load (no N+1, unlike the old job)
            ->chunkById(100, function ($rentals) use (&$created, &$skipped, $builder, $periodStart, $periodEnd) {
                foreach ($rentals as $rental) {
                    // Dedup on the billing PERIOD, not created_at (fixes the old stale-date gap).
                    $exists = Invoice::withoutGlobalScopes()
                        ->where('rental_id', $rental->id)
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $builder->create([
                        'rental' => $rental,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'issue_date' => $periodStart,
                        'due_date' => $periodStart->copy()->addDays(7),
                        'include_rent' => true,
                    ]);
                    $created++;
                }
            });

        $this->info("Rent invoices — created: {$created}, skipped (already billed): {$skipped}.");

        return self::SUCCESS;
    }
}
