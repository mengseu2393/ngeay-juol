<?php

namespace App\Livewire\Mobile;

use App\Enums\UnitStatus;
use App\Livewire\Concerns\ListsTenancies;
use App\Models\Unit;
use App\Support\ActiveProperty;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/tenants — the mobile shell's tenant directory. Same list and server-side
 * filters as Simple Mode's (Concerns\ListsTenancies), in a phone layout: a card
 * per tenancy that opens the tenant's own screen (Mobile\Tenant), tap-to-call,
 * and instant client-side search over the rendered cards.
 *
 * Adding a tenant happens per room (mobile.room.add-tenant), so "Add tenant"
 * here is a sheet listing the vacant rooms that links there.
 */
#[Layout('mobile.layout')]
class Tenants extends Component
{
    use ListsTenancies;

    protected $queryString = [
        'filter' => ['except' => 'active'],
    ];

    // Tenancies and their balances are rendered fresh in render().
    protected $listeners = [
        'tenancy-started' => '$refresh',
        'tenancy-ended' => '$refresh',
        'tenant-updated' => '$refresh',
        'invoices-created' => '$refresh',
        'pay-saved' => '$refresh',
    ];

    public function render()
    {
        $propertyId = ActiveProperty::id();

        // Same "vacant" as Simple Mode's add-tenant room step (SimpleAddTenant).
        $vacantRooms = $propertyId
            ? Unit::query()
                ->where('property_id', $propertyId)
                ->where('status', UnitStatus::Available->value)
                ->orderBy('room_number')
                ->get(['id', 'property_id', 'landlord_id', 'room_number', 'room_type', 'rent_amount', 'rent_currency'])
            : collect();

        return view('livewire.mobile.tenants', [
            'tenants' => $this->tenancyList(),
            'vacantRooms' => $vacantRooms,
        ])->title(__('Tenants'));
    }
}
