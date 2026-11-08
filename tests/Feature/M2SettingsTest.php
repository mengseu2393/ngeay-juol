<?php

namespace Tests\Feature;

use App\Livewire\M2\Home;
use App\Livewire\M2\Profile;
use App\Livewire\M2\Settings;
use App\Support\M2Shell;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * /m2 Settings — what the round picture in every header opens (the old /m
 * Settings tab's options). Pinned: the picture is the way in, Settings has the
 * account / language / sign out, the property and utility rows open /m2's own
 * screens, and Profile only ever edits the signed-in user.
 */
class M2SettingsTest extends M2TestCase
{
    public function test_the_header_picture_opens_settings_but_a_tenants_picture_does_not(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        $this->get(route('m2.home'))->assertSee('id="m2-open-settings"', false)->assertSee(route('m2.settings'), false);
        $this->get(route('m2.tenant', $rental))->assertDontSee('id="m2-open-settings"', false);
    }

    public function test_settings_shows_the_account_language_and_sign_out(): void
    {
        $landlord = $this->landlord('owner@example.com');
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.settings'))
            ->assertOk()
            ->assertSee('Mobile Landlord')
            ->assertSee('owner@example.com')
            ->assertSee(route('locale.switch', 'km'), false)
            ->assertSee(route('locale.switch', 'en'), false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee(route('m2.profile'), false);
    }

    /**
     * Dark mode is chosen here, not in a panel: Light / Dark / System buttons that write the same
     * 'theme' localStorage key the layout reads before first paint (so there is no flash of light).
     */
    public function test_settings_offers_light_dark_and_system_appearance(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(Settings::class)
            ->assertSeeHtml('id="m2-settings-theme-light"')
            ->assertSeeHtml('id="m2-settings-theme-dark"')
            ->assertSeeHtml('id="m2-settings-theme-system"')
            ->assertSeeHtml("localStorage.setItem('theme', v)");
    }

    /**
     * The property and utility rows used to leave the app for /m or the panel; they now stay in /m2
     * whatever the /m flag says, and say which property / how many utilities are on.
     */
    public function test_property_and_utility_rows_stay_in_m2(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Sunrise Apartments');
        $this->utility($property, 'Electricity');
        $this->signIn($landlord, $property);

        foreach ([false, true] as $mobileOn) {
            config(['mobile.enabled' => $mobileOn]);
            $this->get(route('m2.settings'))
                ->assertOk()
                ->assertSee(route('m2.property'), false)
                ->assertSee(route('m2.utilities'), false)
                ->assertSee('Sunrise Apartments')
                ->assertSee(__(':count active', ['count' => 1]))
                ->assertDontSee(route('mobile.property-settings'), false)
                ->assertDontSee(url('app/property-settings'), false)
                ->assertDontSee(url('app/property-utilities'), false);
        }
    }

    public function test_profile_saves_the_signed_in_users_details(): void
    {
        $landlord = $this->landlord('owner@example.com');
        $other = $this->landlord('other@example.com');
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Profile::class)
            ->assertSet('email', 'owner@example.com')
            ->set('name', 'Sophea Tao')
            ->set('phone_number', '012 000 111')
            ->call('saveInfo')
            ->assertHasNoErrors()
            ->assertSee(__('Profile updated.'));

        $this->assertDatabaseHas('users', ['id' => $landlord->id, 'name' => 'Sophea Tao', 'phone_number' => '012 000 111']);
        $this->assertSame('Mobile Landlord', $other->fresh()->name);
    }

    public function test_profile_refuses_an_email_already_taken_and_a_wrong_current_password(): void
    {
        $landlord = $this->landlord('owner@example.com');
        $this->landlord('taken@example.com');
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Profile::class)
            ->set('email', 'taken@example.com')
            ->call('saveInfo')
            ->assertHasErrors('email')
            ->set('current_password', 'not-my-password')
            ->set('password', 'a-new-Password-123')
            ->set('password_confirmation', 'a-new-Password-123')
            ->call('savePassword')
            ->assertHasErrors('current_password');

        $this->assertSame('owner@example.com', $landlord->fresh()->email);
    }

    /**
     * The picker resizes in the browser (a phone photo is bigger than PHP's 2M upload limit) and hands the
     * smaller file to `avatarPhoto`; picking saves it at once. The header must then show it by a path-only
     * URL — an absolute APP_URL one is a broken image whenever the phone uses another address.
     */
    public function test_profile_photo_saves_when_picked_and_shows_by_a_path_only_url(): void
    {
        Storage::fake('public');
        $landlord = $this->landlord('owner@example.com');
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Profile::class)
            ->set('avatarPhoto', UploadedFile::fake()->image('avatar.jpg', 800, 600))
            ->assertHasNoErrors();

        $url = $landlord->fresh()->getFirstMediaUrl('avatar');
        $this->assertNotSame('', $url);
        $this->assertStringStartsWith('/', M2Shell::mediaUrl($url));
        $this->assertStringNotContainsString('://', M2Shell::mediaUrl($url));
        $this->assertNull(M2Shell::mediaUrl(''));
    }

    /**
     * The Vercel deploy keeps photos in an R2/S3 bucket (MEDIA_DISK=s3): its disk is per-instance and
     * wiped, so photos saved there vanished. A bucket photo's URL is on the bucket's own host and must
     * stay absolute — stripping the host like a local /storage/ URL would point it at the app instead.
     */
    public function test_a_profile_photo_kept_in_a_bucket_keeps_the_buckets_url(): void
    {
        config(['media-library.disk_name' => 's3']);
        Storage::fake('s3', ['url' => 'https://pub-test.r2.dev']);
        $landlord = $this->landlord('owner@example.com');
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Profile::class)
            ->set('avatarPhoto', UploadedFile::fake()->image('my photo?.jpg', 800, 600))
            ->assertHasNoErrors();

        $media = $landlord->fresh()->getFirstMedia('avatar');
        $this->assertSame('s3', $media->disk);
        Storage::disk('s3')->assertExists($media->getPathRelativeToRoot());

        $url = M2Shell::mediaUrl($media->getUrl());
        $this->assertStringStartsWith('https://pub-test.r2.dev/', $url);
        $this->assertStringNotContainsString('?', basename($url));

        Livewire::test(Home::class)->assertSeeHtml('src="'.$url.'"');
    }
}
