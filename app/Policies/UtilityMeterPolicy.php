<?php

namespace App\Policies;

class UtilityMeterPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'utility_meter';
    }
}
