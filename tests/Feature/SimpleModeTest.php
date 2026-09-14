<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Livewire\SimpleAddTenant;
use App\Livewire\SimpleEndTenancy;
use App\Livewire\SimpleInvoiceList;
use App\Livewire\SimpleInvoiceView;
use App\Livewire\SimpleRoomList;
use App\Livewire\SimpleTenantList;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SimpleModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_landlord_can_access_simple_mode_page(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->get('/app/simple')
            ->assertSuccessful();
    }

    public function test_non_landlord_tenant_cannot_access_simple_mode_page(): void
    {
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        // Tenant has no subscription so subscription guard fires first (redirect).
        // Either way they must NOT get a 200 on the landlord panel.
        $response = $this->actingAs($tenant)->get('/app/simple');
        $this->assertTrue(
            in_array($response->getStatusCode(), [302, 403], true),
            "Expected redirect or forbidden, got {$response->getStatusCode()}"
        );
    }

    public function test_unauthenticated_user_is_redirected_from_simple_mode(): void
    {
        $this->get('/app/simple')->assertRedirect();
    }

    public function test_landlord_dashboard_redirects_to_simple_mode_when_preference_is_enabled(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app')
            ->assertRedirect(route('filament.landlord.pages.simple'));

        $this->actingAs($landlord)
            ->get('/app/simple')
            ->assertSuccessful();
    }

    public function test_regular_landlord_pages_redirect_to_simple_mode_when_preference_is_enabled(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app/properties')
            ->assertRedirect(route('filament.landlord.pages.simple'));
    }

    /**
     * Simple Mode's own Settings/Utility screens link out to full pages
     * (Property Settings, Utility Rates, Monthly Billing, ...) on purpose.
     * Without this bypass, a landlord with the preference enabled would tap
     * one of those links and immediately get bounced right back to
     * /app/simple by this same middleware, making every "escape hatch" link
     * a dead end.
     */
    public function test_from_simple_query_param_bypasses_the_simple_mode_redirect(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app/properties?from=simple')
            ->assertSuccessful();
    }

    public function test_properties_list_shows_back_to_simple_mode_when_from_simple(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->get('/app/properties?from=simple')
            ->assertSuccessful()
            ->assertSee(__('Back to Simple Mode'));

        // Without the marker, the button must not render.
        $this->get('/app/properties')
            ->assertSuccessful()
            ->assertDontSee(__('Back to Simple Mode'));
    }

    /**
     * The Simple Mode add-tenant/end-tenancy screens' "Full Mode" links used
     * to omit ?from=simple entirely — a landlord with the preference enabled
     * tapping either link got bounced straight back to /app/simple before the
     * full rental form ever loaded.
     */
    public function test_add_tenant_full_mode_link_carries_from_simple_and_is_reachable(): void
    {
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();
        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        // ?unit_id= puts the component straight into the 'details' step, where
        // the "Full Mode" hint (and its link) actually renders.
        $this->get('/app/simple?screen=add-tenant&unit_id='.$unit->id)
            ->assertSuccessful()
            ->assertSee('/app/rentals/create?from=simple', false);

        $this->get('/app/rentals/create?from=simple')->assertSuccessful();

        ActiveProperty::clear();
    }

    /**
     * CreateRental's post-save redirect used to always land on the plain
     * index — losing ?from=simple and getting bounced back to Simple Mode by
     * the very next request, right after the tenant was successfully created.
     */
    public function test_create_rental_redirect_preserves_from_simple(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('landlord'));

        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $component = Livewire::withQueryParams(['from' => 'simple'])
            ->test(CreateRental::class)
            ->assertSet('fromSimpleMode', true)
            ->fillForm([
                'unit_id' => $unit->id,
                'occupant_name' => 'Sok Dara',
                'monthly_rent' => 500,
                'start_date' => now()->toDateString(),
                'status' => RentalStatus::Active->value,
            ])
            ->call('create');

        $component->assertHasNoFormErrors();
        $component->assertRedirect(route('filament.landlord.resources.rentals.index', ['from' => 'simple']));

        ActiveProperty::clear();
    }

    /**
     * A single link only carries ?from=simple for its own request — the very
     * next click inside that full page (e.g. "Edit" on a property reached via
     * the Settings hub) has no marker of its own. Without the escape window
     * this follow-on request would get bounced straight back to Simple Mode
     * mid-task.
     */
    public function test_from_simple_escape_window_covers_follow_on_navigation_without_the_marker(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        // Same client (session persists across requests in a single test).
        $this->actingAs($landlord)
            ->get('/app/properties?from=simple')
            ->assertSuccessful();

        // Follow-on request, no ?from=simple at all.
        $this->get('/app/properties/create')
            ->assertSuccessful();
    }

    public function test_from_simple_escape_window_ends_when_landlord_returns_to_simple_mode(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app/properties?from=simple')
            ->assertSuccessful();

        // Explicitly back to Simple Mode ends the errand.
        $this->get('/app/simple')->assertSuccessful();

        // A later unrelated full-mode URL (no marker) is bounced back again.
        $this->get('/app/properties')
            ->assertRedirect(route('filament.landlord.pages.simple'));
    }

    /**
     * A landlord who has never opted into Simple Mode gets nudged into it on a
     * phone-sized user agent — a suggestion, not a persisted preference change.
     */
    public function test_mobile_user_agent_is_auto_switched_to_simple_mode(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'])
            ->get('/app/properties')
            ->assertRedirect(route('filament.landlord.pages.simple'));

        $this->assertFalse($landlord->fresh()->prefersSimpleLandlordMode());
    }

    public function test_mobile_auto_switch_does_not_repeat_within_the_same_session(): void
    {
        $landlord = $this->makeLandlord();
        $agent = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36';

        $this->actingAs($landlord)
            ->withHeaders(['User-Agent' => $agent])
            ->get('/app/properties')
            ->assertRedirect(route('filament.landlord.pages.simple'));

        // Same session: a manual "switch to full mode" should stick, not bounce
        // straight back to Simple Mode on the very next mobile request.
        $this->withHeaders(['User-Agent' => $agent])
            ->get('/app/properties')
            ->assertSuccessful();
    }

    public function test_desktop_user_agent_is_not_auto_switched_to_simple_mode(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'])
            ->get('/app/properties')
            ->assertSuccessful();
    }

    public function test_simple_mode_menu_action_enables_preference(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect(route('filament.landlord.pages.simple'));

        $this->assertTrue($landlord->fresh()->prefersSimpleLandlordMode());
    }

    public function test_simple_mode_menu_action_disables_preference(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->assertFalse($landlord->fresh()->prefersSimpleLandlordMode());
    }

    public function test_tenant_cannot_toggle_simple_mode_preference(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($tenant)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertForbidden();
    }

    public function test_guest_cannot_toggle_simple_mode_preference(): void
    {
        $this->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect();
    }

    // ── Simple invoice list ────────────────────────────────────────────────────

    public function test_simple_invoice_list_scopes_to_active_property(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other property']);
        $otherUnit = Unit::create([
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'room_number' => '999',
            'room_type' => 'Standard',
            'rent_amount' => 300,
        ]);
        $otherTenant = $this->makeTenant();
        $otherRental = Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'unit_id' => $otherUnit->id,
            'monthly_rent' => 300,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->toDateString(),
        ]);
        $otherInvoice = Invoice::create([
            'rental_id' => $otherRental->id,
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'invoice_number' => 'INV-OTHER',
            'amount_due' => 300.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        // Set session active property to first property
        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleInvoiceList::class, ['filter' => 'all'])
            ->assertSee($invoice->invoice_number)
            ->assertDontSee($otherInvoice->invoice_number);
    }

    // ── Simple record payment ──────────────────────────────────────────────────

    public function test_simple_record_payment_updates_invoice_ledger(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $component = Livewire::actingAs($landlord)
            ->test(SimpleInvoiceList::class)
            ->call('startPay', $invoice->id)
            ->set('payAmount', '250.00')
            ->set('payMethod', PaymentMethod::Cash->value)
            ->call('submitPay');

        $invoice->refresh();

        $this->assertEquals(250.0, (float) $invoice->amount_paid);
        $this->assertEquals(InvoiceStatus::Partial, $invoice->payment_status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 250.00,
            'method' => PaymentMethod::Cash->value,
        ]);
    }

    public function test_simple_record_payment_does_not_write_amount_paid_directly(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        // amount_paid before should be 0
        $this->assertEquals(0, (float) $invoice->fresh()->amount_paid);

        // The Livewire component must use recordPayment() which goes via the ledger.
        // We verify by checking a Payment row exists rather than checking the direct column.
        Livewire::actingAs($landlord)
            ->test(SimpleInvoiceList::class)
            ->call('startPay', $invoice->id)
            ->set('payAmount', '500.00')
            ->set('payMethod', PaymentMethod::Cash->value)
            ->call('submitPay');

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id]);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->payment_status);
    }

    // ── Simple invoice view popup ────────────────────────────────────────────

    /**
     * "View details" is fully client-side, exactly like "Record payment": the
     * list emits a data-slip-url per card, the popup prefetches those slip
     * fragments in the background and injects one on tap. The list must
     * therefore own no view/close state and mount no Livewire popup — if
     * either creeps back in, the tap is gated on a round-trip (and a list
     * re-render) again, which is the mobile lag this design removed.
     */
    public function test_invoice_list_no_longer_owns_the_view_popup_state(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $this->assertFalse(method_exists(SimpleInvoiceList::class, 'viewInvoice'));
        $this->assertFalse(property_exists(SimpleInvoiceList::class, 'viewingInvoiceId'));
        $this->assertFalse(class_exists(SimpleInvoiceView::class));

        Livewire::actingAs($landlord)
            ->test(SimpleInvoiceList::class)
            ->assertSeeHtml('invoice-view-'.$invoice->id)
            ->assertSeeHtml('data-slip-url="'.route('invoices.slip', $invoice).'"');
    }

    public function test_invoice_slip_fragment_renders_the_slip_without_a_layout(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord)
            ->get(route('invoices.slip', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('rw-invoice-wrap')
            ->assertDontSee('<html', false);
    }

    public function test_invoice_slip_fragment_404s_for_another_landlords_invoice(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();
        $other = $this->makeLandlord();

        $this->actingAs($other)
            ->get(route('invoices.slip', $invoice))
            ->assertNotFound();
    }

    public function test_invoice_slip_fragment_requires_login(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->get(route('invoices.slip', $invoice))->assertRedirect();
    }

    // ── Simple room list filter ───────────────────────────────────────────────

    public function test_room_list_filter_pills_narrow_rooms_by_status(): void
    {
        [$landlord, $property, $vacant] = $this->landlordSetup(createRental: false);
        $occupied = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '202',
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'status' => UnitStatus::Occupied,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->assertSee('101')->assertSee('202')
            ->set('filter', 'available')
            ->assertSee('101')->assertDontSee('202')
            ->set('filter', 'occupied')
            ->assertDontSee('101')->assertSee('202')
            ->set('filter', 'all')
            ->assertSee('101')->assertSee('202');
    }

    // ── Simple add tenant ──────────────────────────────────────────────────────

    public function test_simple_add_tenant_creates_rental_for_vacant_room(): void
    {
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->assertEquals(UnitStatus::Available, $unit->status);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('occupantPhone', '012345678')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertDatabaseHas('rentals', [
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'status' => RentalStatus::Active->value,
        ]);
    }

    public function test_simple_add_tenant_saves_the_full_occupant_and_emergency_contact_detail(): void
    {
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('occupantPhone', '012345678')
            ->set('occupantIdCard', '123456789')
            ->set('occupantGender', 'male')
            ->set('occupantDob', '1995-06-15')
            ->set('occupantNationality', 'Khmer')
            ->set('occupantWorkplace', 'ABC Company')
            ->set('occupantAddress', 'House 12, Street 3, Phnom Penh')
            ->set('emergencyContactName', 'Sok Sopheak')
            ->set('emergencyContactPhone', '098765432')
            ->set('emergencyContactRelationship', 'Sibling')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertDatabaseHas('rentals', [
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'occupant_id_card' => '123456789',
            'occupant_gender' => 'male',
            'occupant_nationality' => 'Khmer',
            'occupant_workplace' => 'ABC Company',
            'occupant_address' => 'House 12, Street 3, Phnom Penh',
            'emergency_contact_name' => 'Sok Sopheak',
            'emergency_contact_phone' => '098765432',
            'emergency_contact_relationship' => 'Sibling',
        ]);

        $rental = Rental::where('unit_id', $unit->id)->firstOrFail();
        $this->assertSame('1995-06-15', $rental->occupant_dob->toDateString());
    }

    public function test_simple_add_tenant_attaches_id_card_photos_to_the_rental(): void
    {
        Storage::fake('public');
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $front = UploadedFile::fake()->image('id-front.jpg');
        $back = UploadedFile::fake()->image('id-back.jpg');

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->set('idCardPhotos', [$front, $back])
            ->call('submit')
            ->assertSet('step', 'done');

        $rental = Rental::where('unit_id', $unit->id)->firstOrFail();

        // Same collection RentalResource's desktop form uses, so this shows up
        // on the Full Mode view page too — see SimpleAddTenant's class docblock.
        $this->assertCount(2, $rental->getMedia('id_cards'));
    }

    public function test_simple_add_tenant_rejects_more_than_two_id_card_photos(): void
    {
        Storage::fake('public');
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $photos = [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ];

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->set('idCardPhotos', $photos)
            ->call('submit')
            ->assertHasErrors(['idCardPhotos']);

        $this->assertDatabaseMissing('rentals', ['occupant_name' => 'Sok Dara']);
    }

    public function test_simple_add_tenant_saves_id_card_gender_and_deposit(): void
    {
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('occupantIdCard', 'ID-12345')
            ->set('occupantGender', 'female')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->set('securityDeposit', '1000.00')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertDatabaseHas('rentals', [
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'occupant_id_card' => 'ID-12345',
            'occupant_gender' => 'female',
            'security_deposit' => 1000,
        ]);
    }

    public function test_simple_add_tenant_rejects_occupied_room(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Another Tenant')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->call('submit')
            ->assertHasErrors(['unitId']);
    }

    // ── Simple end tenancy ─────────────────────────────────────────────────────

    /**
     * The room card's kebab menu offers "Edit room price" — saving updates the
     * unit's listed rent AND the active tenancy's monthly_rent, because the card
     * (and the next invoice) read the rental's rent, not the unit's. The old
     * inline "Set Utility" deep-link was removed from the card in the same change.
     */
    public function test_simple_room_list_edit_price_updates_unit_and_active_rental(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();
        $unit->update(['status' => UnitStatus::Occupied]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->assertSee(__('Edit room price'))
            ->assertDontSee(__('Set Utility'))
            ->call('openEditPrice', $unit->id)
            ->assertSet('editingPriceUnitId', $unit->id)
            ->set('priceValue', '175.50')
            ->call('submitEditPrice')
            ->assertHasNoErrors()
            ->assertSet('editingPriceUnitId', null)
            ->assertSet('priceSuccess', true);

        $this->assertEquals('175.50', $unit->refresh()->rent_amount);
        $this->assertEquals('175.50', $rental->refresh()->monthly_rent);
    }

    public function test_simple_room_list_can_open_edit_tenant_from_tenant_popup(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();
        $unit->update(['status' => UnitStatus::Occupied]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->call('viewTenant', $rental->id)
            ->assertSet('viewingRentalId', $rental->id)
            ->assertSee(__('Edit tenant'))
            ->call('editTenant', $rental->id)
            ->assertSet('viewingRentalId', null)
            ->assertSet('editingRentalId', $rental->id);
    }

    public function test_simple_tenant_list_filter_switches_between_active_and_ended(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();
        $rental->update(['occupant_name' => 'Active Person']);
        $endedUnit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'Z9',
            'room_type' => 'Standard',
            'rent_amount' => 100,
            'status' => UnitStatus::Available,
        ]);
        Rental::create([
            'landlord_id' => $landlord->id,
            'unit_id' => $endedUnit->id,
            'tenant_id' => $rental->tenant_id,
            'occupant_name' => 'Ended Person',
            'security_deposit' => 0,
            'start_date' => now()->subYear(),
            'end_date' => now()->subMonth(),
            'monthly_rent' => 100,
            'status' => RentalStatus::Vacated,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleTenantList::class)
            ->assertSee('Active Person')
            ->assertDontSee('Ended Person')
            ->set('filter', 'ended')
            ->assertSee('Ended Person')
            ->assertDontSee('Active Person')
            ->set('filter', 'all')
            ->assertSee('Active Person')
            ->assertSee('Ended Person');
    }

    public function test_simple_end_tenancy_updates_rental_status_and_room_status(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleEndTenancy::class)
            ->call('pickRoom', $unit->id)
            ->set('endDate', now()->toDateString())
            ->set('status', (string) RentalStatus::Vacated->value)
            ->set('freeRoom', true)
            ->call('submit')
            ->assertSet('step', 'done');

        $rental->refresh();
        $unit->refresh();

        $this->assertEquals(RentalStatus::Vacated, $rental->status);
        $this->assertEquals(UnitStatus::Available, $unit->status);
    }

    public function test_end_tenancy_full_mode_link_carries_from_simple_and_is_reachable(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleEndTenancy::class)
            ->call('pickRoom', $unit->id)
            ->set('endDate', now()->toDateString())
            ->set('status', (string) RentalStatus::Vacated->value)
            ->set('freeRoom', true)
            ->call('submit')
            ->assertSet('step', 'done')
            ->assertSee('/app/rentals?from=simple', false);

        $this->get('/app/rentals?from=simple')->assertSuccessful();
    }

    public function test_simple_end_tenancy_mount_param_jumps_straight_to_confirm_step(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();
        $unit->update(['status' => UnitStatus::Occupied]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleEndTenancy::class, ['unitId' => $unit->id])
            ->assertSet('step', 'confirm')
            ->assertSet('unitId', $unit->id);
    }

    public function test_simple_end_tenancy_without_unit_id_starts_at_pick_step(): void
    {
        [$landlord, $property] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleEndTenancy::class)
            ->assertSet('step', 'pick');
    }

    // ── PWA ──────────────────────────────────────────────────────────────────

    public function test_pwa_manifest_is_accessible(): void
    {
        $this->assertFileExists(public_path('manifest.json'));
    }

    public function test_pwa_service_worker_is_accessible(): void
    {
        $this->assertFileExists(public_path('sw.js'));
    }

    /**
     * display-mode:standalone / navigator.standalone are only knowable
     * client-side, so the installed-PWA lock into Simple Mode is a redirect
     * script emitted for eligible roles — assert it's present, and gated
     * correctly by role.
     */
    public function test_pwa_standalone_redirect_script_is_emitted_for_a_landlord(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->get('/app/simple')
            ->assertSuccessful()
            ->assertSee('display-mode: standalone', false);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array{User, Property, Unit, ?Rental, ?Invoice}
     */
    private function landlordSetup(bool $createRental = true): array
    {
        $landlord = $this->makeLandlord();

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Property',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'status' => UnitStatus::Available,
        ]);

        if (! $createRental) {
            return [$landlord, $property, $unit, null, null];
        }

        $tenant = $this->makeTenant();
        $rental = Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Existing Tenant',
            'monthly_rent' => 500,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-001',
            'amount_due' => 500.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        return [$landlord, $property, $unit, $rental, $invoice];
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-simple-mode-'.uniqid().'@example.com',
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

    private function makeTenant(): User
    {
        $tenant = User::factory()->create([
            'name' => 'Test Tenant '.uniqid(),
            'email' => 'tenant-'.uniqid().'@example.com',
        ]);
        $tenant->forceFill(['status' => UserStatus::Active])->save();
        $tenant->assignRole('tenant');

        return $tenant;
    }
}
