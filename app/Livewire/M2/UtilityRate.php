<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Livewire\SimpleUtilityRates;
use App\Models\PropertyUtility;
use App\Support\ActiveProperty;
use App\Support\UtilityCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * /m2/settings/utilities/new and /m2/settings/utilities/{utility} — add or edit
 * one utility rate, turn it off/on, or delete it (after a confirm on this
 * screen). Save, toggle and delete are Simple Mode's (SimpleUtilityRates:
 * validation, policies, active-property scoping); this screen only holds the
 * form and goes back to the list with a toast.
 */
#[Layout('m2.layout')]
class UtilityRate extends SimpleUtilityRates
{
    #[Locked]
    public ?int $utilityId = null;

    /** @var array{name: string, billing_type: int, rate: string, unit_of_measure: string, provider: string, account_ref: string, is_active: bool} */
    public array $form = [];

    public bool $confirmingDelete = false;

    public function mount(?int $utility = null): void
    {
        abort_unless(ActiveProperty::id(), 404);

        if ($utility !== null) {
            $record = $this->scoped($utility);
            abort_unless($record, 404);
            abort_unless(Auth::user()?->can('view', $record), 403);

            $this->utilityId = $record->getKey();
            $this->form = [
                'name' => (string) $record->name,
                'billing_type' => $record->billing_type?->value ?? BillingType::Metered->value,
                'rate' => $record->rate !== null ? rtrim(rtrim(number_format((float) $record->rate, 4, '.', ''), '0'), '.') : '',
                'unit_of_measure' => (string) $record->unit_of_measure,
                'provider' => (string) $record->provider,
                'account_ref' => (string) $record->account_ref,
                'is_active' => (bool) $record->is_active,
            ];

            return;
        }

        abort_unless(Auth::user()?->can('create', PropertyUtility::class), 403);

        $this->form = [
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered->value,
            'rate' => '',
            'unit_of_measure' => 'kWh',
            'provider' => '',
            'account_ref' => '',
            'is_active' => true,
        ];
    }

    /** A new utility takes the chosen name's usual unit and billing type; an existing one keeps what it has. */
    public function updatedFormName(string $name): void
    {
        if ($this->utilityId) {
            return;
        }

        $defaults = UtilityCatalog::defaults($name);
        $this->form['unit_of_measure'] = $defaults['unit'];
        $this->form['billing_type'] = $defaults['billing']->value;
    }

    public function submit(): void
    {
        $this->save($this->utilityId, $this->form);
        $this->back();
    }

    public function toggle(): void
    {
        abort_unless($this->utilityId, 404);

        $this->toggleActive($this->utilityId);
        $this->back();
    }

    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function destroy(): void
    {
        abort_unless($this->utilityId, 404);

        $this->delete($this->utilityId);
        $this->back();
    }

    private function back(): void
    {
        if ($this->successMessage) {
            session()->flash('m2-toast', $this->successMessage);
        }

        $this->redirectRoute('m2.utilities', navigate: true);
    }

    public function render()
    {
        $data = $this->utilityRatesData();
        $record = $this->utilityId ? $data['utilities']->firstWhere('id', $this->utilityId) : null;

        $title = $record ? UtilityCatalog::label($record->name) : __('Add utility');

        return view('livewire.m2.utility-rate', $data + [
            'record' => $record,
            'title' => $title,
        ])->title($title);
    }
}
