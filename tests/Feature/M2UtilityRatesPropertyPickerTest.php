<?php

namespace Tests\Feature;

use App\Livewire\M2\UtilityRates;
use App\Support\ActiveProperty;
use Livewire\Livewire;

/**
 * Why: /m2 utility rates are per property, so a landlord with several must be
 * able to change which property's rates they are looking at without leaving
 * the screen — and never to another landlord's property.
 */
class M2UtilityRatesPropertyPickerTest extends M2TestCase
{
    public function test_the_picker_switches_the_active_property_and_lists_its_utilities(): void
    {
        $landlord = $this->landlord();
        $a = $this->property($landlord, 'Alpha');
        $b = $this->property($landlord, 'Bravo');
        $this->utility($a, 'Electricity');
        $this->utility($b, 'Water');

        $this->signIn($landlord, $a);

        Livewire::test(UtilityRates::class)
            ->assertSee('Alpha')->assertSee('Bravo')
            ->call('switchProperty', $b->id)
            ->assertRedirect(route('m2.utilities'));

        $this->assertSame($b->id, ActiveProperty::id());
    }

    public function test_another_landlords_property_cannot_be_picked(): void
    {
        $landlord = $this->landlord();
        $mine = $this->property($landlord, 'Mine');
        $other = $this->property($this->landlord(), 'Theirs');

        $this->signIn($landlord, $mine);

        Livewire::test(UtilityRates::class)->call('switchProperty', $other->id)->assertNotFound();
        $this->assertSame($mine->id, ActiveProperty::id());
    }
}
