<?php

namespace App\Filament\Pages;

use App\Enums\BillingType;
use App\Enums\PropertyType;
use App\Filament\Resources\PropertyUtilityResource;
use App\Livewire\Concerns\RunsLandlordSetup;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;

/**
 * Setup wizard for a self-registered landlord (/app/setup), steps 2–5 after
 * the /register account step: properties → utilities → rooms & prices → review.
 * The steps and their writes are RunsLandlordSetup (shared with /m2/setup);
 * this is the panel's screen for them.
 *
 * Mobile-first markup with hand-written `rw-setup-*` CSS in rentwise-admin.css —
 * Filament's precompiled Tailwind doesn't carry arbitrary utilities.
 */
class LandlordSetup extends Page
{
    use RunsLandlordSetup;

    protected static string $view = 'filament.pages.landlord-setup';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static ?string $slug = 'setup';

    protected static bool $shouldRegisterNavigation = false;

    /** Session flag set by skip(): stop redirecting here for this session. */
    public const SKIP_SESSION_KEY = 'landlord_setup_skipped';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->hasRole('landlord') && $user->landlordProfile?->onboarding_step !== null;
    }

    public function getTitle(): string
    {
        return __('Set up your rentals');
    }

    protected function getLayoutData(): array
    {
        return ['maxWidth' => MaxWidth::TwoExtraLarge];
    }

    protected function setupDoneUrl(): string
    {
        return route('filament.landlord.pages.dashboard');
    }

    protected function getViewData(): array
    {
        return [
            'propertyTypes' => PropertyType::options(),
            'billingTypes' => BillingType::options(),
            'utilityNames' => PropertyUtilityResource::utilityOptions(),
        ];
    }
}
