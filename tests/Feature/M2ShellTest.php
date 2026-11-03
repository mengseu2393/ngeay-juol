<?php

namespace Tests\Feature;

use App\Enums\BillingType;

/**
 * /m2 is a separate app from /m: its own flag (config/m2.php), its own routes.
 * Each screen's behaviour has its own test class; this pins the wiring — the
 * flag, that every screen opens, and that another landlord's records 404.
 */
class M2ShellTest extends M2TestCase
{
    public function test_it_is_a_404_while_the_flag_is_off_and_for_non_testers(): void
    {
        config(['m2.enabled' => false]);
        $landlord = $this->landlord('owner@example.com');
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.home'))->assertNotFound();
        $this->get(route('m2.dashboard'))->assertNotFound();

        config(['m2.testers' => ['owner@example.com']]);

        $this->get(route('m2.home'))->assertOk();
    }

    public function test_the_m_flag_does_not_open_m2(): void
    {
        config(['m2.enabled' => false, 'mobile.enabled' => true]);
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.home'))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/m2')->assertRedirect(route('login'));
    }

    public function test_every_m2_screen_opens_for_the_landlord(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1');
        $electricity = $this->utility($property, 'Electricity');
        $parking = $this->utility($property, 'Parking', BillingType::Flat, 5);

        $this->signIn($landlord, $property);

        foreach ([
            route('m2.home'),
            route('m2.dashboard'),
            route('m2.room', $rental->unit_id),
            route('m2.meter', [$rental->unit_id, $electricity]),
            route('m2.service', [$rental->unit_id, $parking]),
            route('m2.bill', $rental->unit_id),
            route('m2.tenant', $rental),
            route('m2.slip', $invoice),
            route('m2.settings'),
            route('m2.profile'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_another_landlords_records_are_not_found(): void
    {
        $mine = $this->landlord();
        $other = $this->landlord();
        $theirProperty = $this->property($other, 'Theirs');
        $foreign = $this->rental($other, $theirProperty, 'Z-1', 'Stranger');
        $foreignInvoice = $this->invoice($foreign, 'INV-X');
        $foreignMeter = $this->utility($theirProperty, 'Electricity');

        $this->signIn($mine, $this->property($mine));

        foreach ([
            route('m2.room', $foreign->unit_id),
            route('m2.meter', [$foreign->unit_id, $foreignMeter]),
            route('m2.bill', $foreign->unit_id),
            route('m2.tenant', $foreign),
            route('m2.slip', $foreignInvoice),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_the_page_uses_the_m2_stylesheet_and_not_the_m_shell(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.home'))
            ->assertOk()
            ->assertSee('css/m2.css', false)
            ->assertDontSee('rw-m-tabbar', false)
            ->assertDontSee('filament', false);
    }
}
