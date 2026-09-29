<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SimpleProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * `capture="user"` forces mobile browsers to open the front camera
     * directly, skipping the native picker's "Choose from Library" option —
     * a landlord could only take a fresh selfie, never pick an existing
     * photo. Removing it lets the OS offer both.
     */
    public function test_avatar_input_does_not_force_the_camera_open(): void
    {
        $landlord = $this->makeLandlord();

        Livewire::actingAs($landlord)
            ->test(SimpleProfile::class)
            ->assertDontSee('capture="user"', false)
            ->assertDontSee('capture=', false);
    }

    /**
     * Regression: choosing a photo used to only show a preview — the avatar changed only after
     * a second tap on "Save photo", which landlords never made, so the top bar kept showing
     * their initials. Picking the photo must save it, and tell the page to reload so the
     * Filament top bar (rendered outside this component) picks it up.
     */
    public function test_choosing_a_photo_saves_it_as_the_avatar_immediately(): void
    {
        Storage::fake('public');
        $landlord = $this->makeLandlord();

        Livewire::actingAs($landlord)
            ->test(SimpleProfile::class)
            ->set('avatarPhoto', UploadedFile::fake()->image('game?.jpeg', 600, 600))
            ->assertHasNoErrors()
            ->assertSet('avatarPhoto', null)
            ->assertDispatched('avatar-updated');

        $media = $landlord->fresh()->getFirstMedia('avatar');
        $this->assertNotNull($media);
        // Client file name is never stored ("game?.jpeg" is a hostile path on some servers).
        $this->assertMatchesRegularExpression('/^avatar-[a-z0-9]{8}\.(jpe?g|png)$/', $media->file_name);
    }

    public function test_a_non_image_is_rejected_and_not_kept_as_a_preview(): void
    {
        Storage::fake('public');
        $landlord = $this->makeLandlord();

        Livewire::actingAs($landlord)
            ->test(SimpleProfile::class)
            ->set('avatarPhoto', UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'))
            ->assertHasErrors('avatarPhoto')
            ->assertSet('avatarPhoto', null)
            ->assertNotDispatched('avatar-updated');

        $this->assertNull($landlord->fresh()->getFirstMedia('avatar'));
    }

    /**
     * Regression: media URLs were built from APP_URL, so with APP_URL=http://192.168.18.10:8000
     * but the app opened on 127.0.0.1:8000 the avatar pointed at an unreachable host and the top
     * bar showed initials. They are host-relative now, so they follow whatever host serves the
     * page (also: www/apex, http/https and proxies in production).
     */
    public function test_avatar_url_is_host_relative_so_it_survives_a_mismatched_app_url(): void
    {
        Storage::fake('public');
        config(['app.url' => 'http://192.168.18.10:8000']);
        $landlord = $this->makeLandlord();

        Livewire::actingAs($landlord)
            ->test(SimpleProfile::class)
            ->set('avatarPhoto', UploadedFile::fake()->image('me.png', 300, 300));

        $url = $landlord->fresh()->getFilamentAvatarUrl();

        $this->assertNotNull($url);
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringNotContainsString('192.168.18.10', $url);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-profile-'.uniqid().'@example.com',
        ]);
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::firstOrCreate([
            'slug' => 'starter',
        ], [
            'name' => 'Starter',
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'trial_days' => 0,
            'grace_days' => 7,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonth()->endOfMonth(),
            'auto_renew' => true,
        ]);

        return $landlord;
    }
}
