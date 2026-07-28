<?php

namespace App\Enums;

enum OccupantRole: string
{
    case Primary   = 'primary';
    case CoTenant  = 'co_tenant';
    case Dependent = 'dependent';

    public function label(): string
    {
        return match ($this) {
            self::Primary   => 'Primary Tenant',
            self::CoTenant  => 'Co-Tenant',
            self::Dependent => 'Dependent',
        };
    }
}
