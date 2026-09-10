<?php

namespace App\Filament\Resources\RentalResource\Actions;

use App\Models\Rental;
use App\Services\RoomAccountService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * "Create login" / "Reset password" for a tenancy's portal account.
 *
 * The tenant portal is reached with `rentals.tenant_id`, but the tenancy form
 * only captures free-text occupant details — so until now the only way to give
 * a tenant a login was UnitResource's `generate_rooms` bulk action (a shared
 * per-ROOM account, created at property setup). A landlord who added a tenancy
 * the normal way ended up with a tenant who could not sign in and no remedy on
 * this screen.
 *
 * {@see RoomAccountService::createForRental()} already handles both halves: it
 * creates a dedicated account when the tenancy has none (or is still pointing at
 * the shared room account) and otherwise just resets that account's password.
 * The label flips so the landlord knows which one they are about to do.
 */
class TenantLogin
{
    public static function table(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('tenant_login')
            ->label(fn (Rental $record) => static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalWidth('md')
            ->modalHeading(fn (Rental $record) => (static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
                .' — '.($record->occupant_name ?: __('tenant')))
            ->modalDescription(fn (Rental $record) => static::hasOwnLogin($record)
                ? __('Username').': '.$record->tenant?->username
                : __('A portal login will be created for this tenant so they can sign in and view their invoices.'))
            ->modalSubmitActionLabel(fn (Rental $record) => static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
            ->visible(fn (Rental $record) => (bool) auth()->user()?->can('update', $record))
            ->form(static::schema())
            ->action(fn (Rental $record, array $data) => static::handle($record, $data));
    }

    public static function page(): Action
    {
        return Action::make('tenant_login')
            ->label(fn (Rental $record) => static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalWidth('md')
            ->modalHeading(fn (Rental $record) => (static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
                .' — '.($record->occupant_name ?: __('tenant')))
            ->modalDescription(fn (Rental $record) => static::hasOwnLogin($record)
                ? __('Username').': '.$record->tenant?->username
                : __('A portal login will be created for this tenant so they can sign in and view their invoices.'))
            ->modalSubmitActionLabel(fn (Rental $record) => static::hasOwnLogin($record) ? __('Reset password') : __('Create login'))
            ->visible(fn (Rental $record) => (bool) auth()->user()?->can('update', $record))
            ->form(static::schema())
            ->action(fn (Rental $record, array $data) => static::handle($record, $data));
    }

    /**
     * A tenancy pointing at its unit's shared room account does NOT count as
     * having its own login — that account belongs to the room and outlives the
     * occupant, so this action must still mint a dedicated one. Same rule
     * RoomAccountService applies internally.
     *
     * (`rentals.tenant_id` is NOT NULL, so in practice "no login" means "still
     * on the shared room account"; the null branch is defensive.)
     */
    public static function hasOwnLogin(Rental $record): bool
    {
        return $record->tenant_id !== null
            && $record->tenant_id !== $record->unit?->account_user_id
            && $record->tenant !== null;
    }

    /** @return array<int, Forms\Components\Component> */
    protected static function schema(): array
    {
        return [
            Forms\Components\TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->helperText(__('Leave blank to auto-generate a password.')),
            Forms\Components\Placeholder::make('warning')
                ->hiddenLabel()
                ->content(__('The password is shown once, right after this runs. Copy it before closing the notification — it cannot be retrieved later.')),
        ];
    }

    protected static function handle(Rental $record, array $data): void
    {
        $result = app(RoomAccountService::class)->createForRental($record, $data['password'] ?: null);

        Notification::make()
            ->title($result['created'] ? __('Tenant login created') : __('Tenant password reset'))
            ->body(
                __('Username').': **'.$result['username'].'** · '.__('Password').': **'.$result['password'].'**'
                .'  \n'.__('This password will not be shown again.')
            )
            ->success()->persistent()->send();
    }
}
