<?php

namespace App\Policies;

class PropertyPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'property';
    }
}
