<?php

namespace Tests\Feature;

/**
 * Every /m screen answers for a landlord on their own records — and only theirs.
 * Each screen's behaviour has its own test class; this only pins the wiring.
 */
class MobileRoutesTest extends MobileTestCase
{
    public function test_every_mobile_screen_opens_for_the_landlord(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        foreach ([
            route('mobile.invoices'),
            route('mobile.billing'),
            route('mobile.rooms'),
            route('mobile.room', $rental->unit_id),
            route('mobile.room.edit', $rental->unit_id),
            route('mobile.room.add-tenant', $rental->unit_id),
            route('mobile.room.end-tenancy', $rental->unit_id),
            route('mobile.tenants'),
            route('mobile.tenant', $rental),
            route('mobile.readings'),
            route('mobile.settings'),
            route('mobile.utility-rates'),
            route('mobile.profile'),
            route('mobile.property-settings'),
            route('mobile.add-property'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_another_landlords_room_and_tenancy_are_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');

        $this->signIn($landlord, $property);

        foreach ([
            route('mobile.room', $foreign->unit_id),
            route('mobile.room.edit', $foreign->unit_id),
            route('mobile.room.add-tenant', $foreign->unit_id),
            route('mobile.room.end-tenancy', $foreign->unit_id),
            route('mobile.tenant', $foreign),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
