<?php

namespace App\Policies;

class UtilityUsagePolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'utility_usage';
    }
}
