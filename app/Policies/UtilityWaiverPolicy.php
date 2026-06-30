<?php

namespace App\Policies;

class UtilityWaiverPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'utility_waiver';
    }
}
