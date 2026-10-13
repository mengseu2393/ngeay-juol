<?php

namespace App\Livewire\Mobile;

use App\Enums\UnitStatus;
use App\Livewire\Concerns\StartsTenancy;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\MeterUtilities;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /m/rooms/{unit}/tenant — move a tenant into this room: the occupant, an
 * emergency contact, ID card photos, rent, deposit and move-in meter readings,
 * then the login details once. Same form and rules as Simple Mode's add-tenant
 * flow ({@see StartsTenancy}); the room comes from the URL instead of a picker.
 */
#[Layout('mobile.layout')]
class AddTenant extends Component
{
    use StartsTenancy {
        submit as private startTenancy;
    }
    use WithFileUploads;

    public const MAX_PHOTOS = 2;

    /** 'details' | 'done' */
    #[Locked]
    public string $step = 'details';

    /** Fixed by the URL; the binding already 404s for another landlord's room. */
    #[Locked]
    public ?int $unitId = null;

    /**
     * Photos picked in one go (the camera takes one at a time), appended to
     * $idCardPhotos up to MAX_PHOTOS — so the back doesn't replace the front.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $newIdCardPhotos = [];

    public function mount(Unit $unit): void
    {
        $this->unitId = $unit->getKey();
        $this->startDate = now()->toDateString();
        $this->monthlyRent = (string) ($unit->rent_amount + 0);
    }

    public function updatedNewIdCardPhotos(): void
    {
        $this->validate([
            'newIdCardPhotos' => 'array',
            'newIdCardPhotos.*' => 'image|max:5120',
        ]);

        $this->idCardPhotos = array_slice(
            array_merge($this->idCardPhotos, array_values($this->newIdCardPhotos)),
            0,
            self::MAX_PHOTOS,
        );
        $this->newIdCardPhotos = [];
    }

    public function submit(): void
    {
        abort_unless(Auth::user()?->can('create', Rental::class), 403);
        abort_unless($this->step === 'details', 404);

        $this->startTenancy();
    }

    /**
     * The room from the URL, while it can still take a tenant: available and
     * with no active agreement. A room let in the meantime (another phone,
     * another tab) comes back null, and the form says so.
     */
    protected function loadUnit(?int $id): ?Unit
    {
        $unit = $id ? Unit::query()->with('activeRental')->find($id) : null;

        return $unit && self::canTakeTenant($unit) ? $unit : null;
    }

    private static function canTakeTenant(Unit $unit): bool
    {
        return $unit->status === UnitStatus::Available && ! $unit->activeRental;
    }

    public function render()
    {
        $unit = Unit::query()->with(['property', 'activeRental'])->findOrFail($this->unitId);
        $available = self::canTakeTenant($unit) && Auth::user()?->can('create', Rental::class);

        return view('livewire.mobile.add-tenant', [
            'unit' => $unit,
            'available' => $available,
            'meteredUtilities' => $available && $this->step === 'details' ? MeterUtilities::forUnit($unit) : collect(),
        ])->title(__('Add tenant'));
    }
}
