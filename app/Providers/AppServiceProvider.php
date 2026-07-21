<?php

namespace App\Providers;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LandlordProfile;
use App\Models\MaintenanceMessage;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rental;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TenantProfile;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;
use Filament\Forms\Components\Field;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', \App\Database\Connectors\RdsPostgresConnector::class);
    }

    public function boot(): void
    {
        // Stable polymorphic aliases so *_type columns never store FQCNs
        // (refactor-safe; replaces the old app's no-morph-map footgun). enforceMorphMap
        // requires every model that participates in a morph (activitylog subject/causer,
        // medialibrary, chat) to be listed.
        Relation::enforceMorphMap([
            'user' => User::class,
            'landlord_profile' => LandlordProfile::class,
            'tenant_profile' => TenantProfile::class,
            'property' => Property::class,
            'unit' => Unit::class,
            'rental' => Rental::class,
            'property_utility' => PropertyUtility::class,
            'property_setting' => PropertySetting::class,
            'utility_usage' => UtilityUsage::class,
            'utility_meter' => UtilityMeter::class,
            'utility_waiver' => UtilityWaiver::class,
            'invoice' => Invoice::class,
            'invoice_line' => InvoiceLine::class,
            'payment' => Payment::class,
            'maintenance_request' => MaintenanceRequest::class,
            'maintenance_message' => MaintenanceMessage::class,
            'chat_room' => ChatRoom::class,
            'chat_message' => ChatMessage::class,
            'setting' => Setting::class,
            'subscription_plan' => SubscriptionPlan::class,
            'subscription' => Subscription::class,
            'subscription_history' => SubscriptionHistory::class,
            'subscription_payment' => SubscriptionPayment::class,
        ]);

        // Spatie's Role model lives outside App\Models, so policy auto-discovery
        // misses it — register explicitly so the Shield Role resource is gated
        // (otherwise Filament defaults to "allow" for it).
        Gate::policy(\Spatie\Permission\Models\Role::class, \App\Policies\RolePolicy::class);

        // Centralized super-admin elevation across web, Livewire and Filament —
        // replaces the implicit admin auto-elevation hidden in the old CheckRole.
        Gate::before(fn ($user) => $user->hasRole('super_admin') ? true : null);

        // Subscription feature gates — checked by Filament resources, policies, views.
        // Gate::define('feature.maintenance', fn ($user) => \App\Services\SubscriptionService::isFeatureEnabled($user, 'maintenance'));
        // Gate::define('feature.utilities_metered', fn ($user) => \App\Services\SubscriptionService::isFeatureEnabled($user, 'utilities_metered'));
        // Gate::define('feature.api_access', fn ($user) => \App\Services\SubscriptionService::isFeatureEnabled($user, 'api_access'));
        // Gate::define('feature.multi_manager', fn ($user) => \App\Services\SubscriptionService::isFeatureEnabled($user, 'multi_manager'));
        // Uncomment and add the corresponding feature key to plan->features JSON as plans are created.

        // Localization: auto-translate every auto-derived form field, table column
        // and filter label through __() so the whole admin UI follows the active
        // locale (km translations live in lang/km.json). Explicit ->label() strings
        // are also keyed in that file, so they translate too.
        Field::configureUsing(fn (Field $field) => $field->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
    }
}
