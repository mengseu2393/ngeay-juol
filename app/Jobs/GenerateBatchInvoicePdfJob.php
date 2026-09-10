<?php

namespace App\Jobs;

use App\Http\Controllers\InvoiceDocumentController;
use App\Models\Export;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Renders a large "print all" batch of invoices into one PDF off-request.
 *
 * A Browsershot render holds a Node + Chrome process (~150-250MB) for its whole
 * duration; doing that for 40+ invoices inside the web request pins a PHP-FPM
 * worker for minutes and, at month-end when several landlords print at once,
 * eats the box. Above {@see InvoiceDocumentController::INLINE_BATCH_LIMIT}
 * the controller queues this job instead and the user collects the file from the
 * database notification it sends.
 *
 * Delivery reuses the existing Export plumbing (uuid row + `exports.download`,
 * which already enforces owner + `completed`); nothing parallel is invented.
 */
class GenerateBatchInvoicePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Chrome gets 120s inside InvoicePdfService::makeBatch(); give the job
     * headroom on top for the query, Blade renders and the file write so the
     * worker never kills a render that is still making progress.
     */
    public int $timeout = 300;

    /**
     * One attempt. A retry would spawn a second Chrome for the same batch and
     * send the user a second notification; a failed batch is better re-triggered
     * from the UI, which is one click.
     */
    public int $tries = 1;

    /**
     * @param  array<int, int>  $invoiceIds  already authorised by the controller (LandlordScope-filtered)
     */
    public function __construct(
        public Export $export,
        public array $invoiceIds,
        public string $size = 'a4',
    ) {}

    public function handle(): void
    {
        try {
            // No global-scope juggling needed: a queue worker has no authenticated
            // user, so LandlordScope is a no-op here (see LandlordScope::apply);
            // when the queue runs sync it stays scoped to the requesting landlord,
            // which is also correct. Either way the ids were vetted on the way in.
            $invoices = Invoice::query()
                ->with(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property'])
                ->whereIn('id', $this->invoiceIds)
                ->orderBy('invoice_number')
                ->get();

            if ($invoices->isEmpty()) {
                throw new RuntimeException('No invoices remain for export '.$this->export->getKey().'.');
            }

            $relativeFolder = 'exports';
            $absoluteFolder = storage_path('app/'.$relativeFolder);

            if (! file_exists($absoluteFolder)) {
                mkdir($absoluteFolder, 0755, true);
            }

            // On-disk name is the export uuid; the human file name lives on the
            // Export row and is what exports.download sends to the browser.
            $filePath = $relativeFolder.'/'.$this->export->getKey().'.pdf';

            file_put_contents(
                storage_path('app/'.$filePath),
                app(InvoicePdfService::class)->makeBatch($invoices, $this->size),
            );

            $this->export->update([
                'file_path' => $filePath,
                'status' => 'completed',
            ]);

            Notification::make()
                ->title(__('Invoices ready to download'))
                ->body(__(':count invoices were combined into one PDF.', ['count' => $invoices->count()]))
                ->success()
                ->actions([
                    NotificationAction::make('download')
                        ->label(__('Download'))
                        ->url(route('exports.download', ['file_id' => $this->export->getKey()]), shouldOpenInNewTab: true)
                        ->button(),
                ])
                ->sendToDatabase($this->export->user);
        } catch (Throwable $e) {
            $this->export->update(['status' => 'failed']);

            // The request is long gone, so a failure has to reach the user the
            // same way the success does — otherwise "preparing…" never resolves.
            Notification::make()
                ->title(__('Preparing your invoices failed'))
                ->body(__('The batch invoice PDF could not be generated. Please try again.'))
                ->danger()
                ->sendToDatabase($this->export->user);

            throw $e;
        }
    }
}
