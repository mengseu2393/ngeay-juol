<?php

namespace Tests\Feature;

use App\Livewire\M2\Tenant;
use App\Livewire\M2\TenantEdit;
use App\Models\Subscription;
use App\Support\ActiveProperty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * /m2 Edit tenant. Fields and the save are /m's TenantEdit; pinned here is what
 * /m2 adds: its own screen and route, a save going back to the tenant, and a link
 * that works whichever property is open (Home lists rooms across properties).
 */
class M2EditTenantTest extends M2TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_tenant_screen_opens_edit_tenant(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSeeHtml('id="m2-tenant-edit"')
            ->assertSee(route('m2.tenant.edit', $rental->id));

        $this->get(route('m2.tenant.edit', $rental->id))
            ->assertOk()
            ->assertSee('id="m2-tenant-edit-form"', false)
            ->assertSee('value', false);
    }

    public function test_it_opens_with_another_property_active(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $other = $this->property($landlord, 'Other');
        $this->signIn($landlord, $other);
        $this->assertSame($other->id, ActiveProperty::id());

        $this->get(route('m2.tenant.edit', $rental->id))->assertOk();
    }

    public function test_saving_changes_the_tenant_and_goes_back(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->assertSet('occupantName', 'Dara')
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
            ->set('monthlyRent', '350')
            ->set('securityDeposit', '100')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.tenant', $rental));

        $this->assertSame(__('Tenant details saved'), session('m2-toast'));

        $rental->refresh();
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
        $this->assertSame(350.0, (float) $rental->monthly_rent);
        $this->assertSame(100.0, (float) $rental->security_deposit);
    }

    public function test_a_blank_name_stays_on_the_form(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->set('occupantName', '')
            ->call('submit')
            ->assertHasErrors('occupantName')
            ->assertNoRedirect();

        $this->assertSame('Dara', $rental->fresh()->occupant_name);
    }

    public function test_id_photos_can_be_added_and_removed(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $saved = $rental->addMedia(UploadedFile::fake()->image('old.jpg'))->toMediaCollection('id_cards');
        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->assertSeeHtml('id="m2-et-photo-saved-remove-'.$saved->id.'"')
            ->call('removeSavedPhoto', $saved->id)
            ->assertDontSeeHtml('id="m2-et-photo-saved-remove-'.$saved->id.'"')
            ->set('idCardPhotos', [UploadedFile::fake()->image('new.jpg')])
            ->assertSeeHtml('id="m2-et-photo-new-remove-0"')
            ->call('submit')
            ->assertHasNoErrors();

        $media = $rental->fresh()->getMedia('id_cards');
        $this->assertCount(1, $media);
        $this->assertSame('new.jpg', $media->first()->file_name);
    }

    /** Media Library builds absolute URLs from APP_URL, which breaks the picture when the phone uses another address. */
    public function test_the_tenant_screen_shows_the_emergency_contact_and_path_only_photo_urls(): void
    {
        config(['app.url' => 'http://other-host.test', 'filesystems.disks.public.url' => 'http://other-host.test/storage']);

        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update(['emergency_contact_name' => 'Sok', 'emergency_contact_phone' => '011 222 333']);
        $rental->addMedia(UploadedFile::fake()->image('id.jpg'))->toMediaCollection('id_cards');
        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSeeHtml('id="m2-tenant-emergency"')
            ->assertSee('Sok')
            ->assertSee('011 222 333')
            ->assertDontSeeHtml('src="http');
    }

    public function test_a_read_only_subscription_cannot_edit(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        $open = Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])->set('occupantName', 'Nope');

        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        $open->call('submit')->assertForbidden();

        Livewire::test(Tenant::class, ['rental' => $rental])->assertDontSeeHtml('id="m2-tenant-edit"');

        $this->assertSame('Dara', $rental->fresh()->occupant_name);
    }

    public function test_another_landlords_tenant_is_not_found(): void
    {
        $landlord = $this->landlord();
        $rental = $this->rental($landlord, $this->property($landlord), 'A-1', 'Dara');
        $this->signIn($this->landlord());

        $this->get('/m2/tenants/'.$rental->id.'/edit')->assertNotFound();
    }

    public function test_the_tenant_id_in_the_form_cannot_be_changed_from_the_browser(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])->set('rentalId', $rental->id + 1);
    }
}
