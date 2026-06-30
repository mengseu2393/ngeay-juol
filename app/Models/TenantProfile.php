<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantProfile extends Model
{
    protected $fillable = [
        'user_id',
        'id_card_number',
        'occupation',
        'monthly_income',
        'emergency_contact_name',
        'emergency_contact_phone',
        'guarantor_name',
        'guarantor_phone',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
