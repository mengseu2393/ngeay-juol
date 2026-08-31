<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPaymentStatus;
use App\Filament\Pages\Renewals;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionResource;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Support\Money;
use App\Support\RenewalQueue;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Approvals half of the {@see Renewals} page: money a landlord says they sent,
 * sitting between their bank app and their access.
 *
 * Approving delegates to {@see SubscriptionService::renew()} rather than
 * flipping `status` here. That method finds this exact row by
 * (subscription, covers_from, covers_to, gateway), settles it, moves the
 * period end, writes the history entry and clears any suspension — a local
 * status update would do the first of those five and quietly skip the rest.
 *
 * Because renew() sets the period end to the payment's `covers_to`, approving a
 * stale row can move a subscription *backwards*. The confirmation modal says so
 * in as many words rather than hiding it, so the call stays the admin's.
 */
class AdminPendingPaymentsTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RenewalQueue::pendingPayments()->with(['landlord', 'plan', 'subscription']))
            ->defaultSort('created_at', 'asc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('No payments waiting'))
            ->emptyStateDescription(__('Every subscription payment has been approved or rejected.'))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                Tables\Columns\TextColumn::make('landlord.name')
                    ->label(__('Landlord'))
                    ->description(fn (SubscriptionPayment $record): ?string => $record->landlord?->phone_number)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label(__('Plan'))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (SubscriptionPayment $record): string => Money::format($record->amount, $record->currency))
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('method')
                    ->label(__('Method'))
                    ->formatStateUsing(fn (PaymentMethod|int|string|null $state): string => match (true) {
                        $state instanceof PaymentMethod => $state->getLabel(),
                        is_numeric($state) => PaymentMethod::tryFrom((int) $state)?->getLabel() ?? (string) $state,
                        default => (string) $state,
                    }),

                // What the admin actually reconciles against the bank statement.
                Tables\Columns\TextColumn::make('gateway_ref')
                    ->label(__('Reference'))
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->description(fn (SubscriptionPayment $record): ?string => $record->gateway_transaction_id),

                Tables\Columns\TextColumn::make('covers_from')
                    ->label(__('Covers'))
                    ->formatStateUsing(fn (SubscriptionPayment $record): string => $record->covers_from->format('d M Y').' → '.$record->covers_to->format('d M Y'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Waiting'))
                    ->since()
                    ->color(fn (SubscriptionPayment $record): string => $record->created_at->lt(now()->subDays(3)) ? 'warning' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('note')
                    ->label(__('Note'))
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('landlord_id')
                    ->label(__('Landlord'))
                    ->relationship('landlord', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('method')
                    ->label(__('Method'))
                    ->options(PaymentMethod::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(__('Approve this payment?'))
                    ->modalDescription(fn (SubscriptionPayment $record): string => self::approvalSummary($record))
                    ->modalSubmitActionLabel(__('Approve & renew'))
                    ->action(function (SubscriptionPayment $record): void {
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
                    }),

                Tables\Actions\Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->button()
                    ->outlined()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('Why is it being rejected?'))
                            ->helperText(__('Kept on the payment so the next person to look knows what happened.'))
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (SubscriptionPayment $record, array $data): void {
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
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view')
                        ->label(__('Open payment'))
                        ->icon('heroicon-m-eye')
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionPaymentResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\Action::make('subscription')
                        ->label(__('Open subscription'))
                        ->icon('heroicon-m-credit-card')
                        ->visible(fn (SubscriptionPayment $record): bool => $record->subscription !== null)
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionResource::getUrl('view', ['record' => $record->subscription])),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    /** The tab bar already labels this table. */
    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }

    /**
     * What approving will actually do, in the modal, before it happens — including
     * the case where the row is old enough that settling it would pull the period
     * end backwards from where the subscription already sits.
     */
    private static function approvalSummary(SubscriptionPayment $payment): string
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
