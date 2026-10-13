<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Livewire\Mobile\Tenant;
use App\Livewire\Mobile\TenantEdit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * /m/tenants/{rental} and its "Edit tenant" sheet (Mobile\TenantEdit, which
 * extends Simple Mode's SimpleEditTenant). Pins what a landlord sees of one
 * tenancy, that every read and write stays inside their own records, and the
 * events the rest of /m listens for.
 */
class MobileTenantTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_shows_the_tenancy_its_balance_and_where_to_go_next(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-7', 'Sok Dara');
        $rental->update([
            'occupant_phone' => '012 345 678',
            'occupant_id_card' => 'ID-998877',
            'occupant_workplace' => 'Garment Co',
            'emergency_contact_name' => 'Mother Dara',
            'emergency_contact_phone' => '099 111 222',
            'security_deposit' => 150,
            'notes' => 'Pays on the 5th',
        ]);
        $rental->occupants()->create(['role' => 'co_tenant', 'occupant_name' => 'Chan Vanna', 'occupant_phone' => '011 000 111']);
        $unpaid = $this->invoice($rental, 'INV-UNPAID', 125.5);
        $paid = $this->invoice($rental, 'INV-PAID', status: InvoiceStatus::Paid, issued: now()->subMonth());

        $this->signIn($landlord, $property)
            ->get(route('mobile.tenant', $rental))
            ->assertOk()
            ->assertSee('Sok Dara')
            ->assertSee('A-7')
            ->assertSee('href="tel:012345678"', false)
            ->assertSee('ID-998877')
            ->assertSee('Garment Co')
            ->assertSee('Mother Dara')
            ->assertSee('href="tel:099111222"', false)
            ->assertSee('Chan Vanna')
            ->assertSee('$150.00')
            ->assertSee('Pays on the 5th')
            ->assertSee('$125.50')
            ->assertSee('INV-UNPAID')
            ->assertSee('INV-PAID')
            ->assertSee('id="rw-m-tenant-invoice-'.$unpaid->id.'"', false)
            ->assertSee('id="rw-m-tenant-invoice-'.$paid->id.'"', false)
            // The card's slip URL, inside @js() (JSON in a JS string literal, so slashes come out as \\\/).
            ->assertSee('invoices\\\\\\/'.$unpaid->id.'\\\\\\/slip', false)
            ->assertSee(route('mobile.tenants'))
            ->assertSee(route('mobile.room', $rental->unit_id))
            ->assertSee(route('mobile.room.end-tenancy', $rental->unit_id))
            ->assertDontSee('/app/rentals');
    }

    public function test_another_landlords_tenancy_is_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $theirs = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger Tenant');

        $this->signIn($landlord, $property)
            ->get(route('mobile.tenant', $theirs))
            ->assertNotFound();
    }

    /** A property switch reloads this screen; a tenancy of the previous property goes back to the list. */
    public function test_a_tenancy_of_another_of_my_properties_goes_back_to_the_list(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');
        $rental = $this->rental($landlord, $other, 'B-1', 'Elsewhere Tenant');

        $this->signIn($landlord, $home)
            ->get(route('mobile.tenant', $rental))
            ->assertRedirect(route('mobile.tenants'));
    }

    public function test_id_card_photos_link_to_the_full_size_image(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $media = $rental->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('id_cards');

        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSee('rw-m-tenant-photo-'.$media->id, false)
            ->assertSee($media->getUrl(), false);
    }

    public function test_an_ended_tenancy_shows_move_out_and_no_end_action(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Former Tenant');
        $rental->update(['status' => RentalStatus::Vacated, 'move_out_date' => '2026-09-30', 'move_out_reason' => 'Moved abroad']);

        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSee('Moved abroad')
            ->assertSee(RentalStatus::Vacated->getLabel())
            ->assertDontSee(route('mobile.room.end-tenancy', $rental->unit_id));
    }

    public function test_reset_login_creates_a_portal_account_and_shows_the_password_once(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->unit->update(['account_user_id' => $rental->tenant_id]); // still on the shared room account

        $this->signIn($landlord, $property);

        $component = Livewire::test(Tenant::class, ['rental' => $rental])
            ->call('resetLogin');

        $username = $component->get('newUsername');
        $password = $component->get('newPassword');

        $this->assertNotEmpty($username);
        $this->assertNotEmpty($password);
        $component->assertSee($username)->assertSee($password);
        $this->assertNotSame($rental->tenant_id, $rental->fresh()->tenant_id);
        $this->assertDatabaseHas('users', ['username' => $username]);
    }

    public function test_reset_login_cannot_reach_another_landlords_tenancy(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');
        $stranger = $this->landlord();
        $theirs = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');
        $theirTenant = $theirs->tenant_id;

        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $mine])
            ->call('resetTenantLogin', $theirs->id)
            ->assertNotFound();

        $this->assertSame($theirTenant, $theirs->fresh()->tenant_id);
    }

    public function test_the_screen_refreshes_after_an_edit_and_after_billing_events(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        $component = Livewire::test(Tenant::class, ['rental' => $rental]);

        $rental->update(['occupant_name' => 'Dara Renamed']);
        $component->dispatch('tenant-updated')->assertSee('Dara Renamed');

        $this->invoice($rental, 'INV-LATE', 42);
        $component->dispatch('invoices-created')->assertSee('INV-LATE')->assertSee('$42.00');

        $rental->invoices()->update(['payment_status' => InvoiceStatus::Paid, 'amount_paid' => 42]);
        $component->dispatch('pay-saved')->assertSee(__('Paid'));
    }

    // ── Edit sheet (Mobile\TenantEdit) ──────────────────────────────────────

    public function test_edit_sheet_saves_and_tells_the_screen(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->assertSet('occupantName', 'Dara')
            ->set('occupantName', 'Dara Updated')
            ->set('occupantPhone', '012 999 888')
            ->set('monthlyRent', '320')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('saved', false) // the sheet stays a form, ready for the next edit
            ->assertDispatched('tenant-updated')
            ->assertDispatched('rw-m-sheet-close', name: 'tenant-edit')
            ->assertDispatched('rw-m-toast');

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'occupant_name' => 'Dara Updated',
            'occupant_phone' => '012 999 888',
            'monthly_rent' => 320,
        ]);
    }

    public function test_edit_sheet_validates_like_simple_mode(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->set('occupantName', '')
            ->set('monthlyRent', 'abc')
            ->call('submit')
            ->assertHasErrors(['occupantName' => 'required', 'monthlyRent' => 'numeric'])
            ->assertNotDispatched('tenant-updated');

        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'occupant_name' => 'Dara']);
    }

    public function test_edit_sheet_cannot_open_another_landlords_tenancy(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $theirs = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $theirs->id])->assertNotFound();
    }

    public function test_edit_sheet_uploads_id_card_photos(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->set('idCardPhotos', [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('idCardPhotos', []);

        $this->assertCount(2, $rental->fresh()->getMedia('id_cards'));
    }

    public function test_edit_sheet_rejects_more_than_two_new_photos_or_non_images(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->set('idCardPhotos', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('submit')
            ->assertHasErrors(['idCardPhotos.0']);

        $this->assertCount(0, $rental->fresh()->getMedia('id_cards'));
    }

    public function test_edit_sheet_removes_a_saved_photo(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $media = $rental->addMedia(UploadedFile::fake()->image('wrong.jpg'))->toMediaCollection('id_cards');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->assertSee('rw-m-et-saved-remove-'.$media->id, false)
            ->call('removeSavedPhoto', $media->id)
            ->assertDispatched('tenant-updated')
            ->assertDispatched('rw-m-toast');

        $this->assertCount(0, $rental->fresh()->getMedia('id_cards'));
    }

    public function test_edit_sheet_cannot_remove_another_tenancys_photo(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');
        $stranger = $this->landlord();
        $theirs = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger');
        $theirMedia = $theirs->addMedia(UploadedFile::fake()->image('theirs.jpg'))->toMediaCollection('id_cards');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $mine->id])
            ->call('removeSavedPhoto', $theirMedia->id)
            ->assertNotFound();

        $this->assertCount(1, $theirs->fresh()->getMedia('id_cards'));
    }

    public function test_dismissing_the_sheet_drops_unsaved_edits(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(TenantEdit::class, ['rentalId' => $rental->id])
            ->set('occupantName', 'Half-typed')
            ->set('idCardPhotos', [UploadedFile::fake()->image('front.jpg')])
            ->call('discard')
            ->assertSet('occupantName', 'Dara')
            ->assertSet('idCardPhotos', []);

        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'occupant_name' => 'Dara']);
    }

    public function test_the_tenant_screen_embeds_the_edit_sheet(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Tenant::class, ['rental' => $rental])
            ->assertSeeLivewire(TenantEdit::class);
    }
}
