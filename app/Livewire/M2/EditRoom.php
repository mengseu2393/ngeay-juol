<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\EditRoom as MobileEditRoom;
use App\Services\RoomDetailsService;
use Livewire\Attributes\Layout;

/**
 * /m2/rooms/{unit}/edit — a room's number, type, floor, rent, status, occupants
 * and notes, and deleting it. All the logic (validation, RoomDetailsService,
 * permissions, the subscription write lock) is /m's EditRoom; only the screen and
 * where a delete lands are /m2's. Readings are not here: /m2 has its Meter screen.
 */
#[Layout('m2.layout')]
class EditRoom extends MobileEditRoom
{
    public bool $confirmingDelete = false;

    /** A number can't repeat within a property, as on Add room. A room that already shares one can still save its other details. */
    public function save(): void
    {
        if (trim($this->roomNumber) !== (string) $this->unit->room_number
            && RoomDetailsService::numberTaken($this->unit->property_id, $this->roomNumber, $this->unit->id)) {
            $this->addError('roomNumber', __('This room number is already used in this property.'));

            return;
        }

        parent::save();
    }

    public function openDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    /** A refused delete (someone moved in meanwhile) leaves the toast, not the confirm card. */
    public function delete(): void
    {
        $this->confirmingDelete = false;

        parent::delete();
    }

    protected function afterDelete(): void
    {
        session()->flash('m2-toast', __('Room deleted'));
        $this->redirectRoute('m2.home', navigate: true);
    }

    public function render()
    {
        return view('livewire.m2.edit-room', parent::render()->getData())->title(__('Edit room'));
    }
}
