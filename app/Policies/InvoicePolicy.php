<?php

namespace App\Policies;

class InvoicePolicy extends LandlordOwnedPolicy
{
    protected function resource(): string
    {
        return 'invoice';
    }
}
