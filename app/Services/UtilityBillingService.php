<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;

class UtilityBillingService
{
    /**
     * Compute the charge for a usage reading against its property utility.
     *  - Metered  → amount_used × rate
     *  - Flat     → fixed rate per room
     *  - Shared   → treated as metered until master-meter splitting is built
     * Zeroed when waived (reading-level OR a property/unit/rental waiver).
     *
     * @return array{rate: float, quantity: float, amount: float, is_waived: bool}
     */
    public static function resolveCharge(UtilityUsage $usage): array
    {
        $utility = $usage->propertyUtility;

        $waived = $usage->is_waived || ($utility && UtilityWaiver::isWaivedFor(
            $utility->id,
            $usage->rental_id,
            $usage->unit_id,
        ));

        $rate = $utility ? (float) $utility->rate : 0.0;
        $quantity = (float) $usage->amount_used;

        $amount = 0.0;
        if (! $waived && $utility) {
            $amount = match ($utility->billing_type) {
                BillingType::Flat => round($rate, 2),
                default => round($quantity * $rate, 2), // Metered (and Shared fallback)
            };
        }

        return [
            'rate' => $rate,
            'quantity' => $quantity,
            'amount' => $amount,
            'is_waived' => $waived,
        ];
    }
}
