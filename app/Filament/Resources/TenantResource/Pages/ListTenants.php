<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The directory itself. No CreateAction: tenant accounts are born with their
 * tenancy (see {@see TenantResource}'s class docblock), never standalone here.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Tenant directory');
    }
}
