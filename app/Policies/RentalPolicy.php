<?php

namespace App\Policies;

class RentalPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'rental';
    }
}
