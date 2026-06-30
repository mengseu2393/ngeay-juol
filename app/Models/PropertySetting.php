<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-property configuration (billing/lease defaults, contacts). 1:1 with a property. */
class PropertySetting extends Model
{
    protected $fillable = [
        'property_id',
        'currency',
        'invoice_prefix',
        'due_day_of_month',
        'late_fee',
        'default_lease_months',
        'deposit_policy',
        'water_billing_default',
        'parking_info',
        'insurance_info',
        'caretaker_name',
        'caretaker_phone',
    ];

    protected function casts(): array
    {
        return [
            'late_fee' => 'decimal:2',
            'due_day_of_month' => 'integer',
            'default_lease_months' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
