<?php

namespace App\Livewire\Mobile;

use App\Enums\UnitStatus;
use App\Livewire\Concerns\ManagesRoomList;
use App\Models\Unit;
use App\Models\UtilityUsage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/rooms — every room of the active property as a card. Same list, price
 * edit and first meter reading as Simple Mode's Rooms screen
 * ({@see ManagesRoomList}); the filter pills and search run in the browser
 * (rwMobileRooms in resources/js/mobile/rooms.js), and a tap on a card opens
 * the room's own screen. The tenant itself lives on the Tenant screen, so a
 * card links there instead of carrying a tenant popup.
 */
#[Layout('mobile.layout')]
class Rooms extends Component
{
    use ManagesRoomList {
        submitUtilityReading as private storeFirstReading;
    }

    /** Deep links keep their pill (?filter=occupied). */
    protected $queryString = ['filter' => ['except' => 'all']];

    /** 'all' | 'available' | 'occupied' | 'maintenance' — anything else falls back to all. */
    public function mount(): void
    {
        if (! in_array($this->filter, ['all', 'available', 'occupied', 'maintenance'], true)) {
            $this->filter = 'all';
        }
    }

    /**
     * The shared save has no policy check of its own (Simple Mode relies on the
     * landlord scope alone); /m checks it before anything is written —
     * submitUtilityReadingFor() comes through here too.
     */
    public function submitUtilityReading(): void
    {
        abort_unless(Auth::user()?->can('create', UtilityUsage::class), 403);

        $this->storeFirstReading();
    }

    protected function roomPriceSaved(): void
    {
        $this->dispatch('rw-m-sheet-close', name: 'room-price');
        $this->dispatch('rw-m-toast', message: __('Room price saved.'));
    }

    protected function roomReadingSaved(): void
    {
        $this->dispatch('rw-m-sheet-close', name: 'room-reading');
        $this->dispatch('rw-m-toast', message: __('Utility reading saved.'));
    }

    /** The pill a room's card answers to. */
    public static function statusKey(Unit $unit): string
    {
        return match ($unit->status) {
            UnitStatus::Available => 'available',
            UnitStatus::Occupied => 'occupied',
            UnitStatus::Maintenance, UnitStatus::Unavailable => 'maintenance',
            default => 'other',
        };
    }

    /** Badge colours for an enum's HasColor name — shared by the rooms screens. */
    public static function badgeClass(?string $color): string
    {
        return match ($color) {
            'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            'danger' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
            default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        };
    }

    public function render()
    {
        return view('livewire.mobile.rooms', $this->roomListData())
            ->title(__('Rooms'));
    }
}
