<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordProfile extends Model
{
    protected $fillable = [
        'user_id',
        'company_name',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'payout_details',
        'can_create_tenants',
    ];

    protected function casts(): array
    {
        return [
            'payout_details' => 'array',
            'can_create_tenants' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
