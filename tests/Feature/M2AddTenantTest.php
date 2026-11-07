<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\AddTenant;
use App\Models\Rental;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * /m2 Add tenant has the whole move-in form. It used to be a shorter one, and with
 * no Edit tenant either, details like the ID photos could never be entered from
 * /m2 — so every field is pinned to reach the tenancy, and each has to be on screen.
 */
class M2AddTenantTest extends M2TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function vacantRoom(): array
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Old tenant');
        $rental->update(['status' => RentalStatus::Vacated]);
        $rental->unit->update(['status' => UnitStatus::Available]);

        return [$landlord, $property, $rental->unit->fresh()];
    }

    public function test_every_field_is_on_the_form(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        $form = Livewire::test(AddTenant::class, ['unit' => $unit]);

        foreach (['name', 'phone', 'id', 'gender', 'dob', 'nationality', 'workplace', 'address', 'ec-name', 'ec-phone', 'ec-relationship', 'start', 'rent', 'deposit', 'photo-input'] as $field) {
            $form->assertSeeHtml('id="m2-at-'.$field.'"');
        }
    }

    public function test_every_field_reaches_the_tenancy_with_two_id_photos(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $unit])
            ->set('occupantName', 'Dara Sok')
            ->set('occupantPhone', '012 999 888')
            ->set('occupantIdCard', 'ID-123')
            ->set('occupantGender', 'female')
            ->set('occupantDob', '1990-05-01')
            ->set('occupantNationality', 'Khmer')
            ->set('occupantWorkplace', 'ABA')
            ->set('occupantAddress', 'Phnom Penh')
            ->set('emergencyContactName', 'Sok')
            ->set('emergencyContactPhone', '011 222 333')
            ->set('emergencyContactRelationship', 'Mother')
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('front.jpg')])
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('back.jpg')])
            ->assertSeeHtml('id="m2-at-photo-new-remove-1"')
            ->assertDontSeeHtml('id="m2-at-photo-add"')
            ->set('monthlyRent', '320')
            ->set('securityDeposit', '50')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done')
            ->assertSeeHtml('id="m2-add-tenant-done"');

        $rental = Rental::query()->where('unit_id', $unit->id)->where('status', RentalStatus::Active)->sole();
        $this->assertSame('Dara Sok', $rental->occupant_name);
        $this->assertSame('012 999 888', $rental->occupant_phone);
        $this->assertSame('ID-123', $rental->occupant_id_card);
        $this->assertSame('female', $rental->occupant_gender);
        $this->assertSame('1990-05-01', $rental->occupant_dob->toDateString());
        $this->assertSame('Khmer', $rental->occupant_nationality);
        $this->assertSame('ABA', $rental->occupant_workplace);
        $this->assertSame('Phnom Penh', $rental->occupant_address);
        $this->assertSame('Sok', $rental->emergency_contact_name);
        $this->assertSame('011 222 333', $rental->emergency_contact_phone);
        $this->assertSame('Mother', $rental->emergency_contact_relationship);
        $this->assertSame(320.0, (float) $rental->monthly_rent);
        $this->assertSame(50.0, (float) $rental->security_deposit);
        $this->assertCount(2, $rental->getMedia('id_cards'));
        $this->assertSame(UnitStatus::Occupied, Unit::find($unit->id)->status);
    }

    public function test_a_new_photo_can_be_taken_off_before_saving(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $unit])
            ->set('newIdCardPhotos', [UploadedFile::fake()->image('front.jpg')])
            ->assertSeeHtml('id="m2-at-photo-new-remove-0"')
            ->call('removeIdCardPhoto', 0)
            ->assertDontSeeHtml('id="m2-at-photo-new-remove-0"');
    }

    public function test_a_file_that_is_not_a_picture_shows_an_error(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(AddTenant::class, ['unit' => $unit])
            ->set('newIdCardPhotos', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertHasErrors('newIdCardPhotos.*')
            ->assertSet('idCardPhotos', []);
    }
}
