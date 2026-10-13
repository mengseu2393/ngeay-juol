<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PropertyResource;
use App\Livewire\Concerns\ManagesUnit;
use App\Livewire\SimpleUnitDetail;
use App\Models\Unit;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * One room, start to finish (/app/unit-overview/{unit}) — the full-mode page.
 * Simple Mode has its own screen ({@see SimpleUnitDetail}); both
 * share {@see ManagesUnit} and the same markup partial.
 */
class UnitOverview extends Page
{
    use ManagesUnit;

    protected static string $view = 'filament.pages.unit-overview';

    protected static ?string $slug = 'unit-overview';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(): string
    {
        return '/'.static::getSlug().'/{unit}';
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('viewAny', Unit::class);
    }

    public function mount(int|string $unit): void
    {
        $this->mountUnit($unit);
    }

    public function getTitle(): string
    {
        return __('Room :room', ['room' => $this->unit()->room_number]);
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        $data = $this->unitViewData();

        return $data + [
            'simple' => false,
            'backUrl' => PropertyResource::getUrl('edit', ['record' => $data['unit']->property_id, 'tab' => 'property_tabs-units-tab']),
        ];
    }
}
