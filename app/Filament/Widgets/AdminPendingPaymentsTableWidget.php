<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Renewals;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Tables\Actions\ApproveSubscriptionPaymentAction;
use App\Filament\Tables\Actions\RejectSubscriptionPaymentAction;
use App\Filament\Tables\RowActionGroup;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Support\Money;
use App\Support\RenewalQueue;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Approvals half of the {@see Renewals} page: money a landlord says they sent,
 * sitting between their bank app and their access.
 *
 * Approving and rejecting are {@see ApproveSubscriptionPaymentAction} and
 * {@see RejectSubscriptionPaymentAction} — shared with
 * {@see SubscriptionPaymentResource}, so the same delegation to
 * {@see SubscriptionService::renew()} (and the same modal warning about a stale
 * row pulling the period end *backwards*) applies wherever a payment is settled.
 * This table only picks how they look.
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
                // Both live in App\Filament\Tables\Actions so the payments list
                // settles a payment exactly the way this table does — the modal
                // copy, the renew() delegation and the reason note included.
                // Only the presentation is chosen here.
                ApproveSubscriptionPaymentAction::make()
                    ->button(),

                RejectSubscriptionPaymentAction::make()
                    ->button()
                    ->outlined(),

                RowActionGroup::make([
                    Tables\Actions\Action::make('view')
                        ->label(__('Open payment'))
                        ->icon('heroicon-m-eye')
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionPaymentResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\Action::make('subscription')
                        ->label(__('Open subscription'))
                        ->icon('heroicon-m-credit-card')
                        ->visible(fn (SubscriptionPayment $record): bool => $record->subscription !== null)
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionResource::getUrl('view', ['record' => $record->subscription])),
                ]),
            ]);
    }

    /** The tab bar already labels this table. */
    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }
}
