<?php

namespace App\Policies;

class UnitPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'unit';
    }
}
