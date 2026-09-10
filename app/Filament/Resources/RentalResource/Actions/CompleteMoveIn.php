<?php

namespace App\Filament\Resources\RentalResource\Actions;

use App\Enums\MoveInReadinessStatus;
use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Services\CompleteMoveInAction;
use App\Services\MoveInRuleService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Wires the existing {@see CompleteMoveInAction} to a button.
 *
 * The move-in domain layer was written but never reachable: nothing in either
 * panel called the action, so `move_in_status` / `moved_in_at` were only ever
 * written by the gate-override path. This closes the loop —
 * rules → move-in → tenancy → move-out — without changing the service.
 *
 * Deliberately does NOT snapshot requirements on the way in
 * ({@see MoveInRuleService::prepare()}): a tenancy with no snapshotted
 * requirements reads as ready, which is the correct behaviour while no screen
 * yet collects move-in payments. Once a requirements UI exists it should call
 * prepare() at tenancy creation, and this action will start enforcing the gate
 * on its own.
 */
class CompleteMoveIn
{
    public static function table(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('complete_move_in')
            ->label(__('Complete move-in'))
            ->icon('heroicon-o-home-modern')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Rental $record) => __('Complete move-in').' — '.($record->occupant_name ?: __('tenant')))
            ->modalDescription(fn (Rental $record) => static::readinessSummary($record))
            ->modalSubmitActionLabel(__('Complete move-in'))
            ->visible(fn (Rental $record) => static::isAvailableFor($record))
            ->action(fn (Rental $record) => static::handle($record));
    }

    public static function page(): Action
    {
        return Action::make('complete_move_in')
            ->label(__('Complete move-in'))
            ->icon('heroicon-o-home-modern')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Rental $record) => __('Complete move-in').' — '.($record->occupant_name ?: __('tenant')))
            ->modalDescription(fn (Rental $record) => static::readinessSummary($record))
            ->modalSubmitActionLabel(__('Complete move-in'))
            ->visible(fn (Rental $record) => static::isAvailableFor($record))
            ->action(fn (Rental $record) => static::handle($record));
    }

    /**
     * Only offered for a tenancy that is not yet Active. An Active tenancy is
     * already occupying its room by {@see Rental::booted()}'s own rule, so
     * "Complete move-in" would be a no-op button on every legacy row — and
     * legacy rows are all of them today, since `move_in_status` stays 'draft'
     * for tenancies created before the gated flow existed.
     */
    protected static function isAvailableFor(Rental $record): bool
    {
        return $record->status !== RentalStatus::Active
            && $record->move_in_status !== MoveInReadinessStatus::Active
            && ! $record->hasMovedOut()
            && (bool) auth()->user()?->can('update', $record);
    }

    protected static function readinessSummary(Rental $record): string
    {
        $readiness = app(MoveInRuleService::class)->readiness($record);

        if ($readiness['ready']) {
            return __('Marks the tenancy active, stamps the move-in date and occupies the room.');
        }

        return __('Outstanding move-in balance: :amount. Record the payment or use a manager override first.', [
            'amount' => number_format((float) $readiness['blocking_outstanding'], 2),
        ]);
    }

    protected static function handle(Rental $record): void
    {
        try {
            app(CompleteMoveInAction::class)($record, auth()->id());
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()
                ->title(__('Move-in could not be completed'))
                ->body(__($e->getMessage()))
                ->danger()->send();

            return;
        }

        Notification::make()->title(__('Move-in complete'))->success()->send();
    }
}
