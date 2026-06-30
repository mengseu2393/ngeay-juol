<?php

namespace App\Models;

use App\Enums\ReadingType;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UtilityUsage extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'property_utility_id',
        'unit_id',
        'rental_id',
        'landlord_id',
        'recorded_by_id',
        'reading_type',
        'reading_date',
        'old_reading',
        'new_reading',
        'amount_used',
        'is_waived',
    ];

    protected function casts(): array
    {
        return [
            'reading_type' => ReadingType::class,
            'reading_date' => 'date',
            'old_reading' => 'decimal:3',
            'new_reading' => 'decimal:3',
            'amount_used' => 'decimal:3',
            'is_waived' => 'boolean',
        ];
    }

    public function resolveLandlordId(): ?int
    {
        return Unit::withoutGlobalScopes()->whereKey($this->unit_id)->value('landlord_id');
    }

    public function propertyUtility(): BelongsTo
    {
        return $this->belongsTo(PropertyUtility::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }
}
