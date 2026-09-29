<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cambodian phone numbers are typed every which way ("012 345 678",
 * "012-345-678", "+855 12 345 678", "85512345678"). normalize() reduces them to
 * one local form ("012345678") so registration and login compare like with like.
 */
class PhoneNumber
{
    /** Does the input look like a phone number (as opposed to a username/email)? */
    public static function looksLikePhone(?string $value): bool
    {
        $stripped = preg_replace('/[\s\-().]/', '', (string) $value);

        return (bool) preg_match('/^\+?\d{8,15}$/', $stripped);
    }

    /** Local form: digits only, +855/855 country code folded to a leading 0. */
    public static function normalize(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (str_starts_with($digits, '855') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }

    /**
     * Users (from $query) whose stored phone_number normalizes to $phone. Stored
     * numbers can contain spaces/dashes anywhere, so SQL only narrows by the
     * last three digits and the exact comparison happens here.
     *
     * @param  Builder<User>  $query
     * @return Collection<int, User>
     */
    public static function matchingUsers(Builder $query, string $phone): Collection
    {
        $phone = self::normalize($phone);

        if (strlen($phone) < 3) {
            return new Collection;
        }

        return $query
            ->where('phone_number', 'like', '%'.substr($phone, -3))
            ->get()
            ->filter(fn (User $user) => self::normalize($user->phone_number) === $phone)
            ->values()
            ->toBase();
    }
}
