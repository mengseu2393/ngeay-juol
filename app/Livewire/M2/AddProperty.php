<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\AddProperty as MobileAddProperty;
use App\Services\PropertySetupService;
use Livewire\Attributes\Layout;

/**
 * /m2/settings/property/new — add a property. /m's AddProperty does all of it
 * (one all-or-nothing PropertySetupService::create, the plan's property/room
 * limits, the new property becoming the active one); the screen, where it lands
 * afterwards, and the rooms are /m2's.
 *
 * Rooms work like the setup wizard after /register (the owner asked for that):
 * "Generate rooms" turns floors × rooms per floor × price into a list where any
 * room's number or price can be changed, and rooms added or removed, before
 * saving. Saving without generating first still makes the plain grid.
 */
#[Layout('m2.layout')]
class AddProperty extends MobileAddProperty
{
    /** @var list<array{id: ?int, number: string, floor: string, floor_default: mixed, price: mixed}> */
    public array $grid = [];

    public function generateRooms(): void
    {
        $this->validate($this->generatorRules(), [], $this->generatorLabels());

        $this->grid = PropertySetupService::roomGrid((int) $this->floors, (int) $this->perFloor, $this->roomPrice, $this->grid);
    }

    public function addRoom(): void
    {
        $this->grid[] = ['id' => null, 'number' => '', 'floor' => '', 'floor_default' => null, 'price' => $this->roomPrice];
    }

    public function removeRoom(int $index): void
    {
        unset($this->grid[$index]);
        $this->grid = array_values($this->grid);
    }

    public function save(): void
    {
        if ($this->withRooms) {
            if ($this->grid === []) {
                $this->generateRooms();
            }

            $this->validate([
                'grid' => ['required', 'array', 'min:1'],
                'grid.*.number' => ['required', 'string', 'max:50', 'distinct'],
                'grid.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            ], [
                'grid.required' => __('Add at least one room — press Generate rooms.'),
                'grid.min' => __('Add at least one room — press Generate rooms.'),
            ], [
                'grid' => __('Rooms'),
                'grid.*.number' => __('Room number'),
                'grid.*.price' => __('Price'),
            ]);
        }

        parent::save();
    }

    protected function roomsToCreate(): ?array
    {
        return $this->withRooms ? ['grid' => $this->grid] : null;
    }

    protected function createdUrl(): string
    {
        session()->flash('m2-toast', __('Property added'));

        return $this->withRooms ? route('m2.home') : route('m2.property');
    }

    /** @return array<string, list<string>> */
    private function generatorRules(): array
    {
        return [
            'floors' => ['required', 'integer', 'min:1', 'max:50'],
            'perFloor' => ['required', 'integer', 'min:1', 'max:100'],
            'roomPrice' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    /** @return array<string, string> */
    private function generatorLabels(): array
    {
        return ['floors' => __('Floors'), 'perFloor' => __('Rooms per floor'), 'roomPrice' => __('Price per room')];
    }

    public function render()
    {
        return view('livewire.m2.add-property', parent::render()->getData())->title(__('Add property'));
    }
}
