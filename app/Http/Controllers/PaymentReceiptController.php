<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\LandlordScope;
use App\Services\PaymentReceiptPdfService;
use App\Support\InvoicePaper;
use Illuminate\Http\Request;

/**
 * Streams/downloads a single payment as a receipt PDF (58/65/80 mm thermal slip
 * or an A4/A5 sheet).
 *
 * Payment carries NO landlord_id and no LandlordScope of its own (see CLAUDE.md
 * — it is scoped through payment.invoice.landlord_id and cascade-deletes with
 * its invoice), so the model binding here is deliberately unguarded and
 * {@see guard()} does the whole job against the parent invoice.
 */
class PaymentReceiptController extends Controller
{
    /**
     * Render the receipt. ?size= picks the paper (defaults to the 58 mm thermal
     * slip — the cash-in-hand case this exists for); ?mode=stream opens it inline
     * for an immediate browser print instead of downloading.
     */
    public function pdf(Request $request, Payment $payment)
    {
        $payment->setRelation('invoice', $this->guard($payment));

        $size = in_array($request->query('size'), InvoicePaper::SIZES, true)
            ? $request->query('size')
            : '58mm';

        $mode = $request->query('mode') === 'stream' ? 'inline' : 'attachment';

        $pdfContent = app(PaymentReceiptPdfService::class)->make($payment, $size);
        $name = PaymentReceiptPdfService::filename($payment, 'pdf');

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $mode.'; filename="'.$name.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Resolve the payment's invoice and prove the caller may see it.
     *
     * Only LandlordScope is lifted, not every global scope: a soft-deleted
     * invoice must stay unreachable, so SoftDeletingScope is left in place and a
     * trashed (or missing) invoice 404s. Platform staff see everything; a
     * landlord/manager must match the invoice's landlord_id; anyone else — a
     * tenant on the shared 'web' guard — may only fetch receipts for their own
     * rental.
     */
    protected function guard(Payment $payment): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes([LandlordScope::class])->find($payment->invoice_id);

        abort_if($invoice === null, 404);

        $user = auth()->user();

        if ($user?->isPlatformStaff()) {
            return $invoice;
        }

        $landlordId = $user?->effectiveLandlordId();

        if ($landlordId !== null) {
            abort_unless((int) $invoice->landlord_id === $landlordId, 403);

            return $invoice;
        }

        abort_unless($user && in_array((int) $invoice->rental_id, $user->tenantPortalRentalIds(), true), 403);

        return $invoice;
    }
}
