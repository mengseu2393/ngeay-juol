<?php

namespace Tests\Feature;

use App\Livewire\M2\EditRoom;
use App\Livewire\M2\Setup;
use App\Models\Subscription;
use App\Services\LandlordRegistrationService;
use Database\Seeders\SubscriptionPlanSeeder;
use Livewire\Livewire;

/**
 * The owner found /m2 too quiet — a save or a failure barely showed — so every action now answers
 * with sound, vibration, a coloured toast and motion (public/js/m2-feedback.js). The script reacts
 * to what the server sends; these pin that the server sends the right kind of signal.
 */
class M2FeedbackTest extends M2TestCase
{
    public function test_every_m2_page_loads_the_feedback_script_with_its_texts(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.home'))
            ->assertOk()
            ->assertSee('js/m2-feedback.js?v=', false)
            ->assertSee('window.m2FeedbackText', false)
            ->assertSee('checkFields', false);

        $this->assertFileExists(public_path('js/m2-feedback.js'));
    }

    public function test_a_flashed_message_is_handed_to_the_script_as_a_success_by_default(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->withSession(['m2-toast' => 'Room added'])
            ->get(route('m2.home'))
            ->assertSee('<script type="application/json" id="m2-flash">{"message":"Room added","kind":"success"}</script>', false);

        $this->withSession(['m2-toast' => ['message' => 'Done', 'kind' => 'big']])
            ->get(route('m2.home'))
            ->assertSee('{"message":"Done","kind":"big"}', false);
    }

    public function test_settings_offers_sound_and_vibration_switches(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.settings'))
            ->assertOk()
            ->assertSee('id="m2-settings-sound"', false)
            ->assertSee('id="m2-settings-vibrate"', false)
            ->assertSee(__('Sounds'));
    }

    public function test_a_refused_write_is_a_warning(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->signIn($landlord, $property);
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', 'A-9')
            ->call('save')
            ->assertDispatched('rw-m-toast', kind: 'warning');
    }

    public function test_setup_steps_answer_quietly_and_finishing_flashes_the_big_check(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->actingAs(app(LandlordRegistrationService::class)->register([
            'name' => 'New Owner', 'phone_number' => '011223344', 'password' => 'x',
        ]));

        Livewire::test(Setup::class)
            ->set('properties.0.name', 'Sunrise')
            ->call('next')
            ->assertDispatched('m2-feedback', kind: 'success')
            ->call('back')
            ->call('finish')
            ->assertRedirect(route('m2.home'));

        $this->assertSame(['message' => __('All set! Your rentals are ready.'), 'kind' => 'big'], session('m2-toast'));
    }
}
