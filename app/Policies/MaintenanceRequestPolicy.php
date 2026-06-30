<?php

namespace App\Policies;

class MaintenanceRequestPolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'maintenance_request';
    }
}
