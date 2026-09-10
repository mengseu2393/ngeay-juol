<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\QrLoginToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Mints and redeems the single-use QR login tokens.
 *
 * Replaces the previous QR payload, which embedded the landlord's plaintext
 * password in the URL (browser history, access logs, Referer headers, and the
 * printed image itself). The QR now carries only an opaque token.
 */
class QrLoginTokenService
{
    /**
     * Issue a fresh token for $user and return the signed, absolute URL to encode
     * in the QR image. The raw token is returned to the caller exactly once —
     * only its digest is stored.
     *
     * @return array{token: QrLoginToken, url: string}
     */
    public function issue(User $user, ?User $createdBy = null): array
    {
        $raw = Str::random(QrLoginToken::TOKEN_LENGTH);
        $expiresAt = Carbon::now()->addMinutes(QrLoginToken::LIFETIME_MINUTES);

        $token = QrLoginToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => static::hash($raw),
            'expires_at' => $expiresAt,
            'created_by_id' => $createdBy?->getKey(),
        ]);

        // Signed on top of the random token so a tampered or hand-crafted link is
        // rejected by the 'signed' middleware before it ever reaches the database.
        $url = URL::temporarySignedRoute('qr-login.redeem', $expiresAt, ['token' => $raw]);

        return ['token' => $token, 'url' => $url];
    }

    /**
     * Atomically burn the token and return the user it logs in, or null when the
     * token is unknown, expired, already used, or belongs to a non-active account.
     *
     * The UPDATE ... WHERE used_at IS NULL is what makes redemption single-use:
     * two concurrent scans of the same photographed QR race on one row and exactly
     * one of them sees an affected-row count of 1.
     */
    public function redeem(string $rawToken): ?User
    {
        $token = QrLoginToken::query()
            ->where('token_hash', static::hash($rawToken))
            ->first();

        if (! $token instanceof QrLoginToken) {
            return null;
        }

        if ($token->used_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        $consumed = QrLoginToken::query()
            ->whereKey($token->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        if ($consumed !== 1) {
            return null;
        }

        $user = $token->user()->first();

        // Mirrors LoginController::login()'s status gate — a deactivated or
        // suspended account can never be reached through a QR link either.
        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            return null;
        }

        return $user;
    }

    /**
     * Deterministic digest so the token can be looked up by value. Bcrypt is
     * unusable here (no lookup); the token's 64 chars of entropy make a fast hash
     * safe — there is nothing to brute-force offline.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
