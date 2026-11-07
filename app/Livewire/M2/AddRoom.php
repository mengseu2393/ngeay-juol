<?php

namespace App\Livewire\M2;

use App\Models\Property;
use App\Models\Unit;
use App\Services\RoomDetailsService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /m2/rooms/new — add one room to a property that already exists. Opened from a
 * property (?property=) it goes there; otherwise the screen asks which one, when
 * the landlord has more than one. The new room is Available and bills in the
 * property's currency unless changed here.
 *
 * A number can't repeat within a property: the panel's room generator skips
 * repeats too, and two "A-1"s would be told apart by nothing in the room list or
 * on a bill. The plan's room limit is the Unit model's own check.
 */
#[Layout('m2.layout')]
class AddRoom extends Component
{
    /** Every read goes through LandlordScope (Property::query()), so another landlord's id finds nothing. */
    public ?int $propertyId = null;

    /** Where Back goes: 'home' or 'settings'. */
    #[Locked]
    public string $from = 'home';

    public string $roomNumber = '';

    public string $floorNumber = '';

    public string $roomType = 'Room';

    public string $rentAmount = '';

    public string $rentCurrency = 'USD';

    public string $maxOccupants = '1';

    public string $description = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('create', Unit::class), 403);

        $properties = $this->properties();

        abort_if($properties->isEmpty(), 404);

        $asked = (int) request()->query('property');

        $this->from = request()->query('from') === 'settings' ? 'settings' : 'home';
        $this->propertyId = $properties->firstWhere('id', $asked)?->id
            ?? $properties->firstWhere('id', ActiveProperty::id())?->id
            ?? $properties->first()->id;
        $this->rentCurrency = Money::forPropertyId($this->propertyId);
    }

    /** Another property: its rooms bill in its own currency. */
    public function updatedPropertyId(): void
    {
        $this->rentCurrency = Money::forPropertyId($this->propertyId);
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('create', Unit::class), 403);

        $data = $this->validate(RoomDetailsService::formRules(), [], RoomDetailsService::formLabels());

        $property = $this->propertyId ? Property::query()->find($this->propertyId) : null;

        abort_unless($property, 404);

        if (RoomDetailsService::numberTaken($property->id, $data['roomNumber'])) {
            $this->addError('roomNumber', __('This room number is already used in this property.'));

            return;
        }

        $unit = app(RoomDetailsService::class)->create($property, [
            'room_number' => trim($data['roomNumber']),
            'floor_number' => filled($data['floorNumber']) ? trim($data['floorNumber']) : null,
            'room_type' => trim($data['roomType']),
            'rent_amount' => $data['rentAmount'],
            'rent_currency' => $data['rentCurrency'],
            'max_occupants' => filled($data['maxOccupants']) ? (int) $data['maxOccupants'] : 1,
            'description' => filled($data['description']) ? trim($data['description']) : null,
        ]);

        session()->flash('m2-toast', __('Room added'));
        $this->redirectRoute('m2.room', ['unit' => $unit], navigate: true);
    }

    /** @return Collection<int, Property> */
    private function properties(): Collection
    {
        return Property::query()->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.m2.add-room', [
            'properties' => $this->properties(),
            'roomTypes' => Unit::query()
                ->where('property_id', $this->propertyId)
                ->whereNotNull('room_type')
                ->distinct()
                ->orderBy('room_type')
                ->pluck('room_type'),
            'backUrl' => $this->from === 'settings' ? route('m2.property') : route('m2.home'),
        ])->title(__('Add room'));
    }
}
