<?php

namespace App\Filament\Pages;

use App\Support\ActiveProperty;
use Filament\Pages\Page;

/**
 * Simple mode home screen for daily landlord mobile/PWA use.
 * Provides big-tap-target daily actions instead of the dense full-panel layout.
 * Full Filament panel remains accessible via the "Full Mode" link.
 */
class SimpleDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.simple-dashboard';

    protected static ?string $slug = 'simple';

    public static function getNavigationLabel(): string
    {
        return __('Simple Mode');
    }

    public function getTitle(): string
    {
        return __('Daily work');
    }

    public static function getNavigationGroup(): ?string
    {
        return null; // Appears at top level, not inside a group
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['landlord', 'landlord_manager', 'super_admin']) ?? false;
    }

    public function mount(): void
    {
        // No-op — property context resolved at render time from ActiveProperty
    }

    public function getPropertyId(): ?int
    {
        return ActiveProperty::id();
    }
}
