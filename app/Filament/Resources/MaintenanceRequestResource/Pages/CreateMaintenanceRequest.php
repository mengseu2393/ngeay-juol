<?php

namespace App\Filament\Resources\MaintenanceRequestResource\Pages;

use App\Filament\Resources\MaintenanceRequestResource;
use App\Models\Property;
use App\Models\Unit;
use App\Services\LandlordOwnershipGuard;
use App\Support\ActiveProperty;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceRequest extends CreateRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    /**
     * Both ids arrive as submitted form data — the room Select is built from
     * `->options()`, which emits no `exists` rule — and the lookups below
     * deliberately cross the landlord scope to read the parent row. Without the
     * assertions a forged `unit_id`/`property_id` pair files a maintenance
     * request carrying ANOTHER landlord's `landlord_id`, the same shape as the
     * invoice-side hole {@see LandlordOwnershipGuard} was written for.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['property_id']) && ActiveProperty::id()) {
            $data['property_id'] = ActiveProperty::id();
        }

        if (! empty($data['unit_id'])) {
            $unit = Unit::withoutGlobalScopes()->find($data['unit_id']);
            LandlordOwnershipGuard::assertOwned($unit);

            if (empty($data['property_id'])) {
                $data['property_id'] = $unit->property_id;
            }
        }

        if (! empty($data['property_id'])) {
            $property = Property::withoutGlobalScopes()->find($data['property_id']);
            LandlordOwnershipGuard::assertOwned($property);

            if (empty($data['landlord_id'])) {
                $data['landlord_id'] = $property->landlord_id;
            }
        }

        return $data;
    }
}
