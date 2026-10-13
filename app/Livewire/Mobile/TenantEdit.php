<?php

namespace App\Livewire\Mobile;

use App\Livewire\SimpleEditTenant;
use App\Models\Rental;
use Illuminate\Support\Facades\Auth;

/**
 * The "Edit tenant" bottom sheet on /m/tenants/{rental}. Same fields,
 * validation, scoping and save as Simple Mode's (SimpleEditTenant, which it
 * extends — still dispatching `tenant-updated`), in the mobile shell's own
 * view. The sheet stays usable for another edit after a save (a toast instead
 * of SimpleEditTenant's "Saved" card), a dismissed sheet drops unsaved edits,
 * and a wrong ID photo already on file can be removed here.
 */
class TenantEdit extends SimpleEditTenant
{
    public function submit(): void
    {
        parent::submit();

        if (! $this->saved) {
            return;
        }

        $this->saved = false;
        $this->dispatch('rw-m-sheet-close', name: 'tenant-edit');
        $this->dispatch('rw-m-toast', message: __('Tenant details saved'));
    }

    /** Back to what's on file: the sheet was dismissed without saving. */
    public function discard(): void
    {
        $this->resetErrorBag();
        $this->idCardPhotos = [];
        $this->saved = false;
        $this->mount((int) $this->rentalId);
    }

    /** Remove one of this tenancy's ID-card photos already on file. */
    public function removeSavedPhoto(int $mediaId): void
    {
        $rental = $this->editableRental();

        $media = $rental->getMedia('id_cards')->firstWhere('id', $mediaId);
        abort_unless($media, 404);

        $media->delete();

        $this->dispatch('tenant-updated');
        $this->dispatch('rw-m-toast', message: __('Photo removed'));
    }

    public function render()
    {
        return view('livewire.mobile.tenant-edit', [
            'savedPhotos' => $this->editableRental()->getMedia('id_cards'),
        ]);
    }

    /** The rental as SimpleEditTenant scopes it (active property, LandlordScope), and only if this user may edit it. */
    private function editableRental(): Rental
    {
        $rental = $this->scopedRental();

        abort_unless($rental, 404);
        abort_unless(Auth::user()?->can('update', $rental), 403);

        return $rental;
    }
}
