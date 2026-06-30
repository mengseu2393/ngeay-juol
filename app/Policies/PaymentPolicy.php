<?php

namespace App\Policies;

use Illuminate\Database\Eloquent\Model;

class PaymentPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'payment';
    }

    /** Payments reach their landlord indirectly through the parent invoice. */
    protected function ownerId(Model $record): ?int
    {
        return $record->invoice?->landlord_id;
    }
}
