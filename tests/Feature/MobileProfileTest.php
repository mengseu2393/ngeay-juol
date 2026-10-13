<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Profile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * /m's Profile screen. It is Simple Mode's SimpleProfile with a phone view;
 * these pin the phone's additions (a toast per save, no reload on a new avatar
 * — /m's top bar has no avatar) and that it only ever edits the signed-in user.
 */
class MobileProfileTest extends MobileTestCase
{
    public function test_it_shows_the_signed_in_users_details_with_a_back_link(): void
    {
        $landlord = $this->landlord('me@example.com');
        $landlord->forceFill(['phone_number' => '012345678'])->save();
        $property = $this->property($landlord);

        $this->signIn($landlord, $property)
            ->get(route('mobile.profile'))
            ->assertOk()
            ->assertSee('href="'.route('mobile.settings').'"', false)
            ->assertDontSee('rw-sm-', false)
            ->assertDontSee('capture=', false);

        Livewire::test(Profile::class)
            ->assertSet('name', 'Mobile Landlord')
            ->assertSet('email', 'me@example.com')
            ->assertSet('phone_number', '012345678');
    }

    public function test_saving_info_updates_only_the_signed_in_user_and_toasts(): void
    {
        $landlord = $this->landlord();
        $stranger = $this->landlord('stranger@example.com');

        $this->signIn($landlord);

        Livewire::test(Profile::class)
            ->set('name', '  Sokha Chan ')
            ->set('phone_number', '099 111 222')
            ->set('province', 'Phnom Penh')
            ->call('saveInfo')
            ->assertHasNoErrors()
            ->assertSet('infoSaved', true)
            ->assertDispatched('rw-m-toast', message: __('Profile updated.'));

        $this->assertDatabaseHas('users', ['id' => $landlord->id, 'name' => 'Sokha Chan', 'phone_number' => '099 111 222', 'province' => 'Phnom Penh']);
        $this->assertDatabaseHas('users', ['id' => $stranger->id, 'name' => 'Mobile Landlord', 'email' => 'stranger@example.com', 'phone_number' => null]);
    }

    /** Another user's email can't be taken over through the form. */
    public function test_invalid_info_shows_errors_and_does_not_toast(): void
    {
        $landlord = $this->landlord('mine@example.com');
        $this->landlord('taken@example.com');

        $this->signIn($landlord);

        Livewire::test(Profile::class)
            ->set('name', '')
            ->set('email', 'taken@example.com')
            ->call('saveInfo')
            ->assertHasErrors(['name', 'email'])
            ->assertNotDispatched('rw-m-toast');

        $this->assertSame('mine@example.com', $landlord->fresh()->email);
    }

    public function test_picking_a_photo_saves_the_avatar_and_toasts_without_reloading(): void
    {
        Storage::fake('public');
        $landlord = $this->landlord();
        $this->signIn($landlord);

        $component = Livewire::test(Profile::class)
            ->set('avatarPhoto', UploadedFile::fake()->image('phone photo?.jpeg', 600, 600))
            ->assertHasNoErrors()
            ->assertSet('avatarPhoto', null)
            ->assertDispatched('avatar-updated')
            ->assertDispatched('rw-m-toast', message: __('Profile photo updated.'));

        $this->assertNotNull($landlord->fresh()->getFirstMedia('avatar'));
        $this->assertStringNotContainsString('location.reload', $component->html());
        $component->assertSeeHtml('src="'.$landlord->fresh()->getFirstMediaUrl('avatar').'"');
    }

    public function test_a_non_image_is_rejected_without_a_toast(): void
    {
        Storage::fake('public');
        $landlord = $this->landlord();
        $this->signIn($landlord);

        Livewire::test(Profile::class)
            ->set('avatarPhoto', UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'))
            ->assertHasErrors('avatarPhoto')
            ->assertSet('avatarPhoto', null)
            ->assertNotDispatched('avatar-updated')
            ->assertNotDispatched('rw-m-toast');

        $this->assertNull($landlord->fresh()->getFirstMedia('avatar'));
    }

    public function test_changing_the_password_needs_the_current_one_and_toasts(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord);

        Livewire::test(Profile::class)
            ->set('current_password', 'wrong-password')
            ->set('password', 'NewSecret#2026')
            ->set('password_confirmation', 'NewSecret#2026')
            ->call('savePassword')
            ->assertHasErrors(['current_password'])
            ->assertNotDispatched('rw-m-toast')
            ->set('current_password', 'password')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertSet('current_password', '')
            ->assertDispatched('rw-m-toast', message: __('Password updated.'));

        $this->assertTrue(Hash::check('NewSecret#2026', $landlord->fresh()->password));
    }
}
