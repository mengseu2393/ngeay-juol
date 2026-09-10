<?php

namespace App\Filament\Tables\Actions;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionPayment;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * The other half of {@see ApproveSubscriptionPaymentAction}: the money never
 * arrived, or arrived as something other than what the row claims.
 *
 * The reason is required and kept on the payment, so the next person to open
 * the row — or the landlord asking why they are still locked out — gets an
 * answer instead of a bare Failed badge.
 */
class RejectSubscriptionPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reject';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Reject'));
        $this->icon('heroicon-m-x-circle');
        $this->color('danger');

        $this->form([
            Forms\Components\Textarea::make('reason')
                ->label(__('Why is it being rejected?'))
                ->helperText(__('Kept on the payment so the next person to look knows what happened.'))
                ->required()
                ->rows(3),
        ]);

        // Same reasoning as the approve action: a no-op on the approvals table,
        // load-bearing on a table that lists settled payments too.
        $this->visible(fn (SubscriptionPayment $record): bool => $record->status === SubscriptionPaymentStatus::Pending);

        $this->action(function (SubscriptionPayment $record, array $data): void {
            // Failed, not deleted: a rejected claim is part of the account's
            // history and the landlord can be shown why.
            $record->forceFill([
                'status' => SubscriptionPaymentStatus::Failed,
                'note' => trim(($record->note ? $record->note."\n" : '').__('Rejected by :name on :date: :reason', [
                    'name' => Auth::user()?->name ?? __('admin'),
                    'date' => now()->format('d M Y'),
                    'reason' => $data['reason'],
                ])),
            ])->save();

            Notification::make()
                ->warning()
                ->title(__('Payment rejected'))
                ->send();
        });
    }
}
