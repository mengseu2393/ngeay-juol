<?php

namespace App\Filament\Tables\Actions;

use App\Enums\SubscriptionPaymentStatus;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Widgets\AdminPendingPaymentsTableWidget;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * "This landlord really did send the money" — the one place that settles a
 * pending subscription payment.
 *
 * Approving delegates to {@see SubscriptionService::renew()} rather than
 * flipping `status` here. That method finds this exact row by
 * (subscription, covers_from, covers_to, gateway), settles it, moves the
 * period end, writes the history entry and clears any suspension — a local
 * status update would do the first of those five and quietly skip the rest.
 *
 * Lives here rather than inline in {@see AdminPendingPaymentsTableWidget}
 * because {@see SubscriptionPaymentResource} needs the identical action: a
 * staff member who opens the payments list instead of the Renewals page was
 * otherwise left hand-editing `status`, which is exactly the five-step skip
 * described above.
 *
 * The call site chooses the presentation (`->button()` on the approvals table,
 * plain inside a RowActionGroup elsewhere); everything that decides what
 * happens to the money lives in here.
 */
class ApproveSubscriptionPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approve';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Approve'));
        $this->icon('heroicon-m-check-circle');
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalHeading(__('Approve this payment?'));
        $this->modalDescription(fn (SubscriptionPayment $record): string => static::approvalSummary($record));
        $this->modalSubmitActionLabel(__('Approve & renew'));

        // The approvals table only ever queries pending rows, so this changes
        // nothing there; it is what keeps the action off an already-settled row
        // in a table (the resource's) that lists every status.
        $this->visible(fn (SubscriptionPayment $record): bool => $record->status === SubscriptionPaymentStatus::Pending);

        $this->action(function (SubscriptionPayment $record): void {
            $subscription = $record->subscription;

            if (! $subscription) {
                Notification::make()
                    ->danger()
                    ->title(__('This payment has no subscription to renew'))
                    ->send();

                return;
            }

            // renew() matches the existing row on its gateway string, and a
            // NULL gateway can never equal the '' it would be cast to — the
            // pending row would be stranded and a duplicate booked beside it.
            // 'manual' is what ensurePendingRenewalPayment() writes anyway.
            if (blank($record->gateway)) {
                $record->forceFill(['gateway' => 'manual'])->save();
            }

            SubscriptionService::renew($subscription, [
                'amount' => $record->amount,
                'currency' => $record->currency,
                'method' => $record->method,
                'paid_at' => now(),
                'covers_from' => $record->covers_from,
                'covers_to' => $record->covers_to,
                'gateway' => $record->gateway,
                'gateway_transaction_id' => $record->gateway_transaction_id,
                'gateway_ref' => $record->gateway_ref,
                'receipt_number' => $record->receipt_number,
                'note' => $record->note,
                'recorded_by_id' => Auth::id(),
            ]);

            Notification::make()
                ->success()
                ->title(__('Payment approved'))
                ->body(__(':landlord is now active until :date', [
                    'landlord' => $record->landlord?->name ?? __('The landlord'),
                    'date' => $subscription->refresh()->ends_at?->format('d M Y') ?? '—',
                ]))
                ->send();
        });
    }

    /**
     * What approving will actually do, in the modal, before it happens — including
     * the case where the row is old enough that settling it would pull the period
     * end backwards from where the subscription already sits.
     *
     * renew() sets the period end to the payment's `covers_to` outright, so
     * approving a stale row shortens the subscription. That is stated in the
     * modal rather than blocked, because a late-arriving payment for an earlier
     * period is a real thing an admin sometimes does have to settle — the call
     * stays theirs, it just is not allowed to be a surprise.
     */
    public static function approvalSummary(SubscriptionPayment $payment): string
    {
        $summary = __('Marks :amount as received and moves the period end to :date.', [
            'amount' => Money::format($payment->amount, $payment->currency),
            'date' => $payment->covers_to->format('d M Y'),
        ]);

        $currentEnd = $payment->subscription?->ends_at;

        if ($currentEnd && $payment->covers_to->lt($currentEnd)) {
            $summary .= ' '.__('Warning: this subscription currently runs to :current, so approving would shorten it by :days days.', [
                'current' => $currentEnd->format('d M Y'),
                'days' => (int) $payment->covers_to->diffInDays($currentEnd),
            ]);
        }

        return $summary;
    }
}
