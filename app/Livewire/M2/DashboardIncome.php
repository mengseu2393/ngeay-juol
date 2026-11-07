<?php

namespace App\Livewire\M2;

use App\Models\Property;
use App\Services\M2DashboardService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard/income — money received, month by month for the last six months, for
 * every property or (?property=ID) one. Totals are in the property's currency, or US
 * dollars across properties.
 */
#[Layout('m2.layout')]
class DashboardIncome extends Component
{
    protected $queryString = ['property' => ['except' => '']];

    public string $property = '';

    public function render()
    {
        // An id that is not this landlord's (the scope hides it) means "all properties".
        $property = $this->property === '' ? null : Property::query()->find((int) $this->property);
        $currency = $property ? Money::forPropertyId($property->id) : 'USD';
        $months = M2DashboardService::incomeByMonth($property?->id, $currency);
        $current = $months->first();
        $previous = $months->skip(1)->first();

        return view('livewire.m2.dashboard-income', [
            'property' => $property,
            'currency' => $currency,
            'months' => $months,
            'sum' => (float) $months->sum('total'),
            'peak' => max(1.0, (float) $months->max('total')),
            'trend' => ($previous['total'] ?? 0) > 0 ? (int) round(($current['total'] - $previous['total']) / $previous['total'] * 100) : null,
        ])->title(__('Income'));
    }
}
