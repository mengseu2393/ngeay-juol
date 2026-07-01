<?php

namespace App\Models;

use App\Enums\FirstMonthBillingMode;
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
        // ── Move-in billing rules ──────────────────────────────────────
        'first_month_billing_mode',
        'proration_cutoff_day',
        'require_first_month_upfront',
        'create_invoice_on_move_in',
        'upfront_deposit_months',
        // ── Monthly billing feature flag ────────────────────────────────────────────
        'monthly_billing_enabled',
        // ── Property info & contacts ───────────────────────────────────
        'water_billing_default',
        'parking_info',
        'insurance_info',
        'caretaker_name',
        'caretaker_phone',
    ];

    protected function casts(): array
    {
        return [
            'late_fee'                    => 'decimal:2',
            'due_day_of_month'            => 'integer',
            'default_lease_months'        => 'integer',
            'first_month_billing_mode'    => FirstMonthBillingMode::class,
            'proration_cutoff_day'        => 'integer',
            'require_first_month_upfront' => 'boolean',
            'create_invoice_on_move_in'   => 'boolean',
            'upfront_deposit_months'      => 'integer',
            'monthly_billing_enabled'     => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
