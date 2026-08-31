<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLog;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The /admin System Log reads the activity_log table that LogsActivity already
 * fills. It must stay read-only and stay behind `view_activity_log`.
 */
class SystemLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    public function test_it_lists_activity_recorded_by_the_logs_activity_trait(): void
    {
        $staff = $this->createStaff('support');

        $this->actingAs($staff);

        // A real logged change, not a hand-built Activity row.
        $property = Property::create([
            'landlord_id' => $staff->id,
            'name' => 'Riverside Residences',
        ]);
        $property->update(['name' => 'Riverside Residences II']);

        $activity = Activity::latest('id')->first();
        $this->assertNotNull($activity, 'LogsActivity should have written an entry.');
        $this->assertSame('updated', $activity->event);

        Livewire::test(ListActivityLog::class)
            ->assertCanSeeTableRecords([$activity])
            ->assertTableActionExists('details');
    }

    public function test_the_log_is_read_only(): void
    {
        $this->actingAs($this->createStaff('super_admin'));

        $this->assertFalse(ActivityLogResource::canCreate());
        $this->assertFalse(ActivityLogResource::canDeleteAny());
        $this->assertSame(
            ['index'],
            array_keys(ActivityLogResource::getPages()),
            'The log should have no create or edit page.',
        );
    }

    public function test_it_is_gated_on_the_view_activity_log_permission(): void
    {
        // support carries view_activity_log; landlord_manager deliberately does not
        // (see RolesAndPermissionsSeeder), so it is the honest negative case.
        $this->actingAs($this->createStaff('support'));
        $this->assertTrue(ActivityLogResource::canViewAny());

        $this->actingAs($this->createStaff('landlord_manager'));
        $this->assertFalse(ActivityLogResource::canViewAny());
    }

    public function test_it_summarises_the_changed_fields(): void
    {
        $activity = new Activity(['properties' => ['attributes' => ['monthly_rent' => 250, 'payment_status' => 2]]]);

        $this->assertSame(['Monthly Rent', 'Payment Status'], ActivityLogResource::changedFields($activity));
    }

    public function test_it_labels_subjects_from_the_morph_map(): void
    {
        // Labels run through __(), so pin the locale rather than the translation.
        $this->app->setLocale('en');

        $this->assertSame('Property Utility', ActivityLogResource::subjectLabel('property_utility'));
        $this->assertSame('—', ActivityLogResource::subjectLabel(null));
    }

    /**
     * Every LogsActivity model now sets dontSubmitEmptyLogs(), so touching a field
     * outside the logged set no longer writes a contentless "updated" row — which is
     * what filled the log with entries whose Changed column read "—".
     */
    public function test_a_change_outside_the_logged_fields_writes_no_entry(): void
    {
        $staff = $this->createStaff('super_admin');
        $this->actingAs($staff);

        // property_type is set explicitly: the column has a DB default of 1 that the
        // model never syncs, so omitting it makes every later save log a phantom
        // "property_type: null → 1" change and muddies the count.
        $property = Property::create([
            'landlord_id' => $staff->id,
            'name' => 'Riverside Residences',
            'property_type' => PropertyType::Apartment,
        ]);

        $before = Activity::count();

        // `city` is not in Property's logOnly() list, so this changes nothing logged.
        $property->update(['city' => 'Phnom Penh']);
        $this->assertSame($before, Activity::count(), 'An unlogged field should write no entry.');

        // A logged field still does.
        $property->update(['name' => 'Riverside Residences II']);
        $this->assertSame($before + 1, Activity::count());
    }

    protected function createStaff(string $role): User
    {
        $user = User::create([
            'name' => 'Staff User',
            'email' => $role.'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
