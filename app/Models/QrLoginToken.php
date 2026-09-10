<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, short-lived credential-free login token backing the admin
 * "QR Code Login" page.
 *
 * The raw token is never persisted — only its SHA-256 digest — so the QR image
 * itself is the sole copy. Redemption is atomic (see QrLoginTokenService), which
 * is what makes a photographed QR code unusable after its first scan.
 */
class QrLoginToken extends Model
{
    /**
     * How long a freshly minted QR login link stays valid. Deliberately short:
     * the QR is meant to be scanned in the room where it was generated.
     */
    public const LIFETIME_MINUTES = 15;

    /** Bytes of randomness in the raw token handed to the QR encoder. */
    public const TOKEN_LENGTH = 64;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
