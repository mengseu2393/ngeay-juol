<?php

namespace App\Livewire\Mobile;

use App\Enums\OccupantRole;
use App\Livewire\Concerns\ManagesTenancy;
use App\Models\Rental;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/tenants/{rental} — one tenancy on the phone: the occupant and their
 * co-occupants, ID-card photos, the agreement (what RentalResource's view page
 * shows a landlord, minus the desktop-only fields), the balance and recent
 * invoices, and the tenant-portal login (Concerns\ManagesTenancy, shared with
 * Simple Mode's Tenants screen). Editing is Mobile\TenantEdit in a sheet;
 * ending the tenancy and the room are their own /m screens.
 *
 * The route binding already 404s another landlord's tenancy (LandlordScope).
 * One of the landlord's own tenancies in a property other than the active one
 * (a property switch reloads this screen) sends them back to the list.
 */
#[Layout('mobile.layout')]
class Tenant extends Component
{
    use ManagesTenancy;

    private const RECENT_INVOICES = 5;

    public Rental $rental;

    protected $listeners = [
        'tenant-updated' => 'handleTenantUpdated',
        'tenancy-ended' => '$refresh',
        'invoices-created' => '$refresh',
        'pay-saved' => '$refresh',
    ];

    public function mount(Rental $rental): void
    {
        $this->rental = $rental;

        abort_unless(Auth::user()?->can('view', $rental), 403);

        if (! $this->scopedRental($rental->id)) {
            $this->redirectRoute('mobile.tenants', navigate: true);
        }
    }

    public function handleTenantUpdated(): void
    {
        $this->rental->refresh();
    }

    /** The portal-login reset, for this screen's tenancy only. */
    public function resetLogin(): void
    {
        $this->resetTenantLogin($this->rental->id);
        $this->rental->refresh();
    }

    public function render()
    {
        // A copy: the relations loaded for display stay out of the component's state.
        $rental = (clone $this->rental)->load(['unit.property', 'tenant', 'occupants', 'depositSettlement']);

        $recentInvoices = $rental->invoices()
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_INVOICES)
            ->get();

        $invoiceCount = $rental->invoices()->count();

        // totalDueFor() sums whatever invoices are loaded — only the unpaid ones here.
        $rental->setRelation('invoices', $rental->invoices()->whereIn('payment_status', static::unpaidInvoiceStatuses())->get());
        $hasBalance = $rental->invoices->sum(fn ($invoice) => $invoice->balance_usd + $invoice->balance_khr) > 0;

        $primaryName = $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant'));

        $coOccupants = $rental->occupants
            ->reject(fn ($occupant) => $occupant->role === OccupantRole::Primary)
            ->values();

        return view('livewire.mobile.tenant', [
            'primaryName' => $primaryName,
            'coOccupants' => $coOccupants,
            'photos' => $rental->getMedia('id_cards'),
            'totalDue' => static::totalDueFor($rental),
            'hasBalance' => $hasBalance,
            'recentInvoices' => $recentInvoices,
            'invoiceCount' => $invoiceCount,
        ])->title($primaryName);
    }
}
