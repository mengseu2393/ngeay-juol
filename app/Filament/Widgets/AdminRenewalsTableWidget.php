<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\Renewals;
use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\Money;
use App\Support\RenewalQueue;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Renewals half of the {@see Renewals} page.
 *
 * Ordered by period end ascending, which is also urgency order: the longest
 * expired sits at the top and the not-yet-due at the bottom, with grace in
 * between. The "Bucket" column names what each row *is*, since a raw date does
 * not distinguish a subscription still inside its grace window from one whose
 * access the retention timer is already counting down.
 *
 * Every mutation goes through {@see SubscriptionService} rather than touching
 * columns here — it is what writes the history rows and fires the landlord
 * notifications, and a second write path would silently skip both.
 */
class AdminRenewalsTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RenewalQueue::needsAttention()->with(['landlord', 'plan']))
            ->defaultSort('ends_at', 'asc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('Nothing to renew'))
            ->emptyStateDescription(__('No subscription expires within :days days, and none is suspended or unstarted.', ['days' => RenewalQueue::HORIZON_DAYS]))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                Tables\Columns\TextColumn::make('landlord.name')
                    ->label(__('Landlord'))
                    ->description(fn (Subscription $record): ?string => $record->landlord?->phone_number)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label(__('Plan'))
                    ->badge()
                    ->color('gray')
                    ->description(fn (Subscription $record): string => $record->interval->getLabel()),

                Tables\Columns\TextColumn::make('bucket')
                    ->label(__('Needs'))
                    ->badge()
                    ->getStateUsing(fn (Subscription $record): string => RenewalQueue::bucketBadge(RenewalQueue::bucket($record))['label'])
                    ->color(fn (Subscription $record): string => RenewalQueue::bucketBadge(RenewalQueue::bucket($record))['color']),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label(__('Period ends'))
                    ->date('d M Y')
                    ->description(fn (Subscription $record): ?string => self::countdown($record))
                    ->sortable(),

                Tables\Columns\TextColumn::make('grace_ends_at')
                    ->label(__('Grace until'))
                    ->date('d M Y')
                    ->placeholder(__('no grace'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('price')
                    ->label(__('Renews at'))
                    ->formatStateUsing(fn (Subscription $record): string => Money::format($record->price, $record->currency))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(SubscriptionStatus::class),

                Tables\Filters\SelectFilter::make('plan_id')
                    ->label(__('Plan'))
                    ->relationship('plan', 'name')
                    ->preload(),

                Tables\Filters\Filter::make('overdue_only')
                    ->label(__('Already past its end date'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDate('ends_at', '<', now())),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Money already in hand: book it and move the period forward.
                    Tables\Actions\Action::make('renew')
                        ->label(__('Record payment & renew'))
                        ->icon('heroicon-m-arrow-path')
                        ->color('success')
                        ->form([
                            Forms\Components\TextInput::make('amount')
                                ->label(__('Amount'))
                                ->required()->numeric()->prefix('$')
                                ->default(fn (Subscription $record) => $record->price),
                            Forms\Components\Select::make('method')
                                ->label(__('Payment method'))
                                ->options(PaymentMethod::class)
                                ->default(PaymentMethod::BankTransfer->value),
                            Forms\Components\DatePicker::make('paid_at')
                                ->label(__('Payment date'))
                                ->default(now()),
                            Forms\Components\Textarea::make('note')->label(__('Note'))->rows(2),
                        ])
                        ->action(function (Subscription $record, array $data): void {
                            SubscriptionService::renew($record, $data);

                            Notification::make()
                                ->success()
                                ->title(__('Renewed until :date', ['date' => $record->refresh()->ends_at?->format('d M Y')]))
                                ->send();
                        })
                        ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Suspended),

                    // No money yet: raise the invoice and let the landlord know.
                    Tables\Actions\Action::make('requestPayment')
                        ->label(__('Ask landlord to pay'))
                        ->icon('heroicon-m-paper-airplane')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading(__('Request the next payment'))
                        ->modalDescription(fn (Subscription $record): string => __(
                            'Creates a pending payment for the period starting :date and notifies :landlord. Nothing is charged.',
                            [
                                'date' => $record->ends_at?->format('d M Y') ?? '—',
                                'landlord' => $record->landlord?->name ?? __('the landlord'),
                            ],
                        ))
                        ->action(function (Subscription $record): void {
                            $payment = SubscriptionService::ensurePendingRenewalPayment($record);

                            $payment
                                ? Notification::make()->success()->title(__('Payment requested'))->send()
                                : Notification::make()->warning()->title(__('This subscription has no period end to bill for'))->send();
                        })
                        ->visible(fn (Subscription $record): bool => $record->ends_at !== null),

                    Tables\Actions\Action::make('extend')
                        ->label(__('Extend'))
                        ->icon('heroicon-m-arrow-right-circle')
                        ->color('gray')
                        ->form([
                            Forms\Components\TextInput::make('days')
                                ->label(__('Days'))
                                ->required()->numeric()->minValue(1)->default(30),
                            Forms\Components\Textarea::make('reason')->label(__('Reason'))->required()->rows(2),
                        ])
                        ->action(fn (Subscription $record, array $data) => SubscriptionService::extend($record, (int) $data['days'], $data['reason'])),

                    Tables\Actions\Action::make('reactivate')
                        ->label(__('Reactivate'))
                        ->icon('heroicon-m-play-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Subscription $record) => SubscriptionService::reactivate($record))
                        ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::Suspended),

                    Tables\Actions\Action::make('open')
                        ->label(__('Open subscription'))
                        ->icon('heroicon-m-arrow-top-right-on-square')
                        ->color('gray')
                        ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),

                    Tables\Actions\Action::make('landlord')
                        ->label(__('Open landlord'))
                        ->icon('heroicon-m-user-circle')
                        ->color('gray')
                        ->visible(fn (Subscription $record): bool => $record->landlord !== null)
                        ->url(fn (Subscription $record): string => LandlordResource::getUrl('view', ['record' => $record->landlord])),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    /** The tab bar already labels this table. */
    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }

    /** "in 12 days" / "18 days ago" — the distance, which a date alone hides. */
    private static function countdown(Subscription $subscription): ?string
    {
        if (! $subscription->ends_at) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($subscription->ends_at, false);

        return match (true) {
            $days === 0 => __('today'),
            $days > 0 => __('in :count days', ['count' => $days]),
            default => __(':count days ago', ['count' => abs($days)]),
        };
    }
}
