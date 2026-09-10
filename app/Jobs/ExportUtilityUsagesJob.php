<?php

namespace App\Jobs;

use App\Models\Export;
use App\Models\Property;
use App\Models\UtilityUsage;
use App\Services\InvoicePdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Browsershot\Browsershot;
use Throwable;

class ExportUtilityUsagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Export $export,
        public int $propertyId,
        public array $filters
    ) {}

    public function handle(): void
    {
        try {
            $property = Property::findOrFail($this->propertyId);
            $query = UtilityUsage::query()
                ->whereHas('unit', fn ($q) => $q->where('property_id', $this->propertyId))
                ->with([
                    'propertyUtility:id,property_id,name,unit_of_measure,rate',
                    'unit:id,property_id,room_number',
                    'rental:id,tenant_id,occupant_name',
                    'rental.tenant:id,name',
                ]);

            // Apply Time Period Filters
            $timePeriod = $this->filters['time_period'] ?? 'all';
            if ($timePeriod === 'this_year') {
                $query->whereYear('reading_date', now()->year);
            } elseif ($timePeriod === 'last_year') {
                $query->whereYear('reading_date', now()->subYear()->year);
            } elseif ($timePeriod === 'custom') {
                if (! empty($this->filters['from_date'])) {
                    $query->whereDate('reading_date', '>=', $this->filters['from_date']);
                }
                if (! empty($this->filters['until_date'])) {
                    $query->whereDate('reading_date', '<=', $this->filters['until_date']);
                }
            }

            // Apply Utility Type Filters
            $utilityTypes = $this->filters['utility_types'] ?? [];
            if (! empty($utilityTypes) && ! in_array('all', $utilityTypes)) {
                $query->whereIn('property_utility_id', $utilityTypes);
            }

            // Order by date desc
            $usages = $query->orderBy('reading_date', 'desc')->get();

            // Format Selection
            $format = strtolower($this->filters['format'] ?? 'csv');
            $fileName = 'utility_export_'.$this->propertyId.'_'.time().'.'.$format;
            $relativeFolder = 'exports';
            $absoluteFolder = storage_path('app/'.$relativeFolder);

            if (! file_exists($absoluteFolder)) {
                mkdir($absoluteFolder, 0755, true);
            }

            $filePath = $relativeFolder.'/'.$fileName;
            $fullPath = storage_path('app/'.$filePath);

            if ($format === 'csv') {
                $this->generateCsv($fullPath, $usages);
            } elseif ($format === 'xlsx') {
                $this->generateExcel($fullPath, $usages);
            } elseif ($format === 'pdf') {
                $this->generatePdf($fullPath, $usages, $property);
            }

            $this->export->update([
                'file_path' => $filePath,
                'status' => 'completed',
            ]);

            // Send notification to user
            Notification::make()
                ->title(__('Utility Export Ready'))
                ->body(__('Your utility usage export for :property is ready.', ['property' => $property->name]))
                ->success()
                ->actions([
                    NotificationAction::make('download')
                        ->label(__('Download'))
                        ->url(route('exports.download', ['file_id' => $this->export->id]), shouldOpenInNewTab: true)
                        ->button(),
                ])
                ->sendToDatabase($this->export->user);

        } catch (Throwable $e) {
            $this->export->update(['status' => 'failed']);
            throw $e;
        }
    }

    protected function generateCsv(string $fullPath, $usages): void
    {
        $handle = fopen($fullPath, 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Header columns
        fputcsv($handle, [
            __('Date'),
            __('Utility'),
            __('Unit'),
            __('Tenant Name'),
            __('Previous Reading'),
            __('Current Reading'),
            __('Used'),
            __('Unit of Measure'),
            __('Rate'),
            __('Amount Billed'),
            __('Status'),
        ]);

        foreach ($usages as $usage) {
            $rate = (float) ($usage->propertyUtility?->rate ?? 0.0);
            $amountBilled = $usage->is_waived ? 0.0 : ((float) $usage->amount_used * $rate);
            $tenantName = $usage->rental?->occupant_name ?: ($usage->rental?->tenant?->name ?? '—');

            fputcsv($handle, [
                $usage->reading_date ? $usage->reading_date->format('Y-m-d') : '—',
                $usage->propertyUtility?->name ?? '—',
                $usage->unit?->room_number ?? '—',
                $tenantName,
                $usage->old_reading,
                $usage->new_reading,
                $usage->amount_used,
                $usage->propertyUtility?->unit_of_measure ?? '',
                $rate,
                $amountBilled,
                $usage->is_waived ? __('Waived') : __('Active'),
            ]);
        }

        fclose($handle);
    }

    protected function generateExcel(string $fullPath, $usages): void
    {
        $spreadsheet = new Spreadsheet;

        // --- Sheet 1: Summary ---
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle(__('Summary'));

        $sheet1->setCellValue('A1', __('Monthly Utility Breakdown'));
        $sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet1->setCellValue('A3', __('Month'));
        $sheet1->setCellValue('B3', __('Total Cost'));
        $sheet1->getStyle('A3:B3')->getFont()->setBold(true);

        $monthsData = array_fill(1, 12, 0.0);
        $totalCost = 0.0;
        foreach ($usages as $usage) {
            if ($usage->is_waived || ! $usage->reading_date) {
                continue;
            }
            $month = $usage->reading_date->month;
            $rate = (float) ($usage->propertyUtility?->rate ?? 0.0);
            $cost = (float) $usage->amount_used * $rate;
            $monthsData[$month] += $cost;
            $totalCost += $cost;
        }

        $row = 4;
        for ($m = 1; $m <= 12; $m++) {
            $monthName = date('F', mktime(0, 0, 0, $m, 10));
            $sheet1->setCellValue('A'.$row, __($monthName));
            $sheet1->setCellValue('B'.$row, $monthsData[$m]);
            $sheet1->getStyle('B'.$row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $row++;
        }

        $sheet1->setCellValue('A'.$row, __('Total'));
        $sheet1->setCellValue('B'.$row, $totalCost);
        $sheet1->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
        $sheet1->getStyle('B'.$row)->getNumberFormat()->setFormatCode('$#,##0.00');

        // --- Sheet 2: Raw Data ---
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle(__('Raw Data'));

        // Headers
        $headers = [
            __('Date'),
            __('Utility'),
            __('Unit'),
            __('Tenant Name'),
            __('Previous Reading'),
            __('Current Reading'),
            __('Used'),
            __('Unit of Measure'),
            __('Rate'),
            __('Amount Billed'),
            __('Status'),
        ];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet2->setCellValue($col.'1', $header);
            $sheet2->getStyle($col.'1')->getFont()->setBold(true);
            $col++;
        }

        $r = 2;
        foreach ($usages as $usage) {
            $rate = (float) ($usage->propertyUtility?->rate ?? 0.0);
            $amountBilled = $usage->is_waived ? 0.0 : ((float) $usage->amount_used * $rate);
            $tenantName = $usage->rental?->occupant_name ?: ($usage->rental?->tenant?->name ?? '—');

            $sheet2->setCellValue('A'.$r, $usage->reading_date ? $usage->reading_date->format('Y-m-d') : '—');
            $sheet2->setCellValue('B'.$r, $usage->propertyUtility?->name ?? '—');
            $sheet2->setCellValue('C'.$r, $usage->unit?->room_number ?? '—');
            $sheet2->setCellValue('D'.$r, $tenantName);
            $sheet2->setCellValue('E'.$r, (float) $usage->old_reading);
            $sheet2->setCellValue('F'.$r, (float) $usage->new_reading);
            $sheet2->setCellValue('G'.$r, (float) $usage->amount_used);
            $sheet2->setCellValue('H'.$r, $usage->propertyUtility?->unit_of_measure ?? '');
            $sheet2->setCellValue('I'.$r, $rate);
            $sheet2->setCellValue('J'.$r, $amountBilled);
            $sheet2->setCellValue('K'.$r, $usage->is_waived ? __('Waived') : __('Active'));

            $sheet2->getStyle('E'.$r.':G'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet2->getStyle('I'.$r.':J'.$r)->getNumberFormat()->setFormatCode('$#,##0.00');
            $r++;
        }

        // Auto size columns
        foreach (range('A', 'K') as $columnID) {
            $sheet2->getColumnDimension($columnID)->setAutoSize(true);
        }
        $sheet1->getColumnDimension('A')->setAutoSize(true);
        $sheet1->getColumnDimension('B')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);
    }

    protected function generatePdf(string $fullPath, $usages, Property $property): void
    {
        $monthsData = array_fill(1, 12, 0.0);
        $totalCost = 0.0;
        foreach ($usages as $usage) {
            if ($usage->is_waived || ! $usage->reading_date) {
                continue;
            }
            $month = $usage->reading_date->month;
            $rate = (float) ($usage->propertyUtility?->rate ?? 0.0);
            $cost = (float) $usage->amount_used * $rate;
            $monthsData[$month] += $cost;
            $totalCost += $cost;
        }

        $html = view('reports.utility-usages-pdf', [
            'property' => $property,
            'usages' => $usages,
            'monthsData' => $monthsData,
            'totalCost' => $totalCost,
            'filters' => $this->filters,
        ])->render();

        // Browsershot wiring + the silent dompdf fallback are shared with the
        // invoice PDFs (see InvoicePdfService::renderThroughBrowsershot); only
        // the landscape geometry and this log line are specific to the report.
        $pdf = InvoicePdfService::renderThroughBrowsershot(
            $html,
            fn (Browsershot $browsershot) => $browsershot
                ->margins(10, 10, 10, 10)
                ->format('A4')
                ->landscape(),
            function () use ($html): string {
                $pdf = Pdf::loadHTML($html);
                $pdf->setPaper('A4', 'landscape');

                return $pdf->output();
            },
            'Browsershot utility export PDF render failed; falling back to dompdf.',
            ['property_id' => $property->id],
        );

        file_put_contents($fullPath, $pdf);
    }
}
