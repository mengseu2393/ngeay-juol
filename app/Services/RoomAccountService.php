<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Per-room login accounts. Each unit has ONE permanent account (role: tenant)
 * that the landlord manages — created when rooms are generated, password reset
 * when an occupant changes. Tenants may optionally use it to view invoices.
 */
class RoomAccountService
{
    /** A stable, unique, username from the property + room number, e.g. "riverside-residences-101". */
    public function generateUsername(Unit $unit): string
    {
        $base = Str::slug(($unit->property?->name ?: 'room').'-'.$unit->room_number);
        if ($base === '') {
            $base = 'room-'.$unit->getKey();
        }

        $username = $base;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.'-'.(++$i);
        }

        return $username;
    }

    /** Readable password (letters + numbers, no symbols) that's easy to hand to a tenant. */
    public function randomPassword(): string
    {
        return Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);
    }

    /**
     * Create the room's login account. Idempotent — returns the existing account
     * untouched if one is already linked.
     *
     * @return array{user: User, username: string, password: ?string, created: bool}
     */
    public function createForUnit(Unit $unit, ?string $password = null): array
    {
        if ($unit->account_user_id && $unit->account) {
            return ['user' => $unit->account, 'username' => $unit->account->username, 'password' => null, 'created' => false];
        }

        $password = $password ?: $this->randomPassword();
        $username = $this->generateUsername($unit);

        $user = new User([
            'name' => trim('Room '.$unit->room_number.($unit->property ? ' — '.$unit->property->name : '')),
            'username' => $username,
            'password' => $password, // 'hashed' cast hashes on save
        ]);
        $user->forceFill([                 // status & created_by_id are not mass-assignable
            'status' => UserStatus::Active,
            'created_by_id' => $unit->landlord_id,
        ]);
        $user->save();
        $user->assignRole('tenant');

        $unit->account_user_id = $user->getKey();
        $unit->saveQuietly();
        $unit->setRelation('account', $user);

        return ['user' => $user, 'username' => $username, 'password' => $password, 'created' => true];
    }

    /**
     * Per-tenant login: ensure THIS tenancy has its own dedicated account (one
     * login per tenant, distinct from the shared room account) and (re)set its
     * password. Used when adding/managing tenants from the unit's tenant list.
     *
     * If the rental's tenant is still the shared room account (or unset), a fresh
     * account is created from the occupant's name and linked to the rental.
     * Otherwise the existing dedicated account's password is reset.
     *
     * @return array{user: User, username: string, password: string, created: bool}
     */
    public function createForRental(Rental $rental, ?string $password = null): array
    {
        $password = $password ?: $this->randomPassword();
        $sharedRoomAccount = $rental->unit?->account_user_id;

        // Already has its own login (not the shared room account) → just reset it.
        if ($rental->tenant_id && $rental->tenant_id !== $sharedRoomAccount && $rental->tenant) {
            $rental->tenant->update(['password' => $password]); // 'hashed' cast hashes on save

            return ['user' => $rental->tenant, 'username' => $rental->tenant->username, 'password' => $password, 'created' => false];
        }

        $username = $this->generateRentalUsername($rental);

        $user = new User([
            'name' => $rental->occupant_name ?: trim('Tenant — Room '.$rental->unit?->room_number),
            'username' => $username,
            'password' => $password, // 'hashed' cast hashes on save
        ]);
        $user->forceFill([                 // status & created_by_id are not mass-assignable
            'status' => UserStatus::Active,
            'created_by_id' => $rental->landlord_id,
        ]);
        $user->save();
        $user->assignRole('tenant');

        $rental->tenant_id = $user->getKey();
        $rental->save();
        $rental->setRelation('tenant', $user);

        return ['user' => $user, 'username' => $username, 'password' => $password, 'created' => true];
    }

    /** A unique username for a tenancy, from the room number + occupant name, e.g. "101-sok-dara". */
    public function generateRentalUsername(Rental $rental): string
    {
        $base = Str::slug(trim(($rental->unit?->room_number ? $rental->unit->room_number.'-' : '').($rental->occupant_name ?: 'tenant')));
        if ($base === '') {
            $base = 'tenant-'.$rental->getKey();
        }

        $username = $base;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.'-'.(++$i);
        }

        return $username;
    }

    /**
     * Reset the room account's password for the next occupant.
     *
     * @return array{username: string, password: string}|null  null if the room has no account yet
     */
    public function resetPassword(Unit $unit, ?string $password = null): ?array
    {
        if (! $unit->account) {
            return null;
        }

        $password = $password ?: $this->randomPassword();
        $unit->account->update(['password' => $password]); // 'hashed' cast hashes on save

        return ['username' => $unit->account->username, 'password' => $password];
    }
}
