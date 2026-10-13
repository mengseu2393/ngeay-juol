<?php

namespace App\Livewire;

use App\Enums\BillingType;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use App\Support\Money;
use App\Support\UtilityCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Simple Mode's Utility Rates screen (/app/simple?screen=utility-rates): the
 * property's utility catalogue as cards, with an Alpine-owned add/edit popup
 * that opens instantly from data already on the card. The only server
 * round-trips are save / toggle / delete. Replaces the ?from=simple detour
 * through PropertyUtilityResource's list page.
 */
class SimpleUtilityRates extends Component
{
    public string $search = '';

    /** Filter: 'all' | 'active' | 'inactive' */
    public string $filter = 'all';

    public ?string $successMessage = null;

    protected $queryString = ['filter'];

    /** Reading-history popup: which utility, and which room's readings. */
    public ?int $historyUtilityId = null;

    public ?int $historyUnitId = null;

    public function openHistory(int $utilityId): void
    {
        $utility = $this->scoped($utilityId);
        abort_unless($utility, 404);
        abort_unless(Auth::user()?->can('viewAny', UtilityUsage::class), 403);

        $this->historyUtilityId = $utility->getKey();
        $this->historyUnitId = $this->historyRooms()->first()?->getKey();
    }

    public function selectHistoryRoom(int $unitId): void
    {
        abort_unless($this->historyRooms()->contains('id', $unitId), 404);
        $this->historyUnitId = $unitId;
    }

    public function closeHistory(): void
    {
        $this->historyUtilityId = null;
        $this->historyUnitId = null;
    }

    private function historyRooms()
    {
        return ActiveProperty::id()
            ? Unit::query()->where('property_id', ActiveProperty::id())->orderBy('room_number')->get(['id', 'room_number'])
            : collect();
    }

    /**
     * One-shot create/update from the client-side popup. $data carries name,
     * billing_type, rate, unit_of_measure, provider, account_ref, is_active.
     */
    public function save(?int $id, array $data): void
    {
        $propertyId = ActiveProperty::id();
        abort_unless($propertyId, 404);

        $record = $id ? $this->scoped($id) : null;
        abort_if($id && ! $record, 404);
        abort_unless(Auth::user()?->can($record ? 'update' : 'create', $record ?? PropertyUtility::class), 403);

        $validated = validator($data, [
            'name' => 'required|string|max:100',
            'billing_type' => 'required|integer|in:'.implode(',', array_map(fn ($c) => $c->value, BillingType::cases())),
            'rate' => 'required|numeric|min:0',
            'unit_of_measure' => 'required|string|max:30',
            'provider' => 'nullable|string|max:100',
            'account_ref' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ], [], [
            'name' => __('Name'),
            'billing_type' => __('Billing type'),
            'rate' => __('Rate'),
            'unit_of_measure' => __('Unit of measure'),
            'provider' => __('Provider'),
        ])->validate();

        $attributes = [
            'name' => $validated['name'],
            'billing_type' => BillingType::from((int) $validated['billing_type']),
            'rate' => $validated['rate'],
            'unit_of_measure' => $validated['unit_of_measure'],
            'provider' => ($validated['provider'] ?? null) ?: null,
            'account_ref' => ($validated['account_ref'] ?? null) ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        if ($record) {
            $record->update($attributes);
            $this->successMessage = __('Utility rate saved.');
        } else {
            PropertyUtility::create($attributes + [
                'property_id' => $propertyId,
                'currency' => Money::forPropertyId($propertyId),
            ]);
            $this->successMessage = __('Utility added.');
        }

        $this->dispatch('utility-rate-saved');
    }

    public function toggleActive(int $id): void
    {
        $record = $this->scoped($id);
        abort_unless($record, 404);
        abort_unless(Auth::user()?->can('update', $record), 403);

        $record->update(['is_active' => ! $record->is_active]);
        $this->successMessage = $record->is_active ? __('Utility activated.') : __('Utility deactivated.');
    }

    public function delete(int $id): void
    {
        $record = $this->scoped($id);
        abort_unless($record, 404);
        abort_unless(Auth::user()?->can('delete', $record), 403);

        $record->delete();
        $this->successMessage = __('Utility deleted.');
        $this->dispatch('utility-rate-deleted');
    }

    private function scoped(int $id): ?PropertyUtility
    {
        return PropertyUtility::query()
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($id)
            ->first();
    }

    public function render()
    {
        return view('livewire.simple-utility-rates', $this->utilityRatesData());
    }

    /**
     * Everything the screen shows. Shared with the /m mobile shell's Utility
     * Rates screen (Mobile\UtilityRates), which renders it in its own view.
     *
     * @return array<string, mixed>
     */
    protected function utilityRatesData(): array
    {
        $propertyId = ActiveProperty::id();

        $utilities = $propertyId
            ? PropertyUtility::query()
                ->where('property_id', $propertyId)
                ->when($this->filter === 'active', fn ($q) => $q->where('is_active', true))
                ->when($this->filter === 'inactive', fn ($q) => $q->where('is_active', false))
                ->when($this->search !== '', function ($q) {
                    $s = '%'.trim($this->search).'%';
                    $q->where(fn ($sq) => $sq->where('name', 'like', $s)->orWhere('provider', 'like', $s));
                })
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
            : collect();

        // Custom names already on this property (e.g. "Trash / Cleaning") join
        // the preset list so the edit popup's <select> can show them.
        $nameOptions = UtilityCatalog::options();
        foreach ($utilities->pluck('name')->filter()->unique() as $name) {
            $nameOptions[$name] ??= $name;
        }
        $nameDefaults = collect(array_keys($nameOptions))
            ->mapWithKeys(function (string $name) {
                $d = UtilityCatalog::defaults($name);

                return [$name => ['unit' => $d['unit'], 'billing' => $d['billing']->value]];
            })
            ->all();

        return [
            'utilities' => $utilities,
            'nameOptions' => $nameOptions,
            'nameDefaults' => $nameDefaults,
            'billingTypes' => BillingType::options(),
            'historyUtility' => $this->historyUtilityId ? $utilities->firstWhere('id', $this->historyUtilityId) ?? $this->scoped($this->historyUtilityId) : null,
            'historyRooms' => $this->historyUtilityId ? $this->historyRooms() : collect(),
            'currency' => $propertyId ? Money::forPropertyId($propertyId) : 'USD',
        ];
    }
}
