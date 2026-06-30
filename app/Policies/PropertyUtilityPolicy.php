<?php

namespace App\Policies;

class PropertyUtilityPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'property_utility';
    }
}
