<?php

namespace App\Livewire\M2;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\RoomDueService;
use App\Support\ActiveProperty;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard — sketch 3. Four tabs for the active property: Revenue (money
 * received, per room, for a date range), Due (who owes what, longest-waiting
 * first), Occupancy (which rooms are let) and History (meter readings or bills,
 * per room, for a date range).
 *
 * Read-only. Every query is bounded to the active property (ActiveProperty::id()
 * is re-validated against the landlord scope on every read), and only the open
 * tab's data is queried.
 *
 * Money: invoices keep amount_due / balance in the property's currency, so Due
 * and Bill read those directly; a payment may have been taken in the other
 * currency, so Revenue sums the payment's own USD/KHR twin (converted at the
 * rate saved on the payment) in the property's currency.
 */
#[Layout('m2.layout')]
class Dashboard extends Component
{
    public const TABS = ['revenue', 'due', 'occupancy', 'history'];

    /** How many of the period's payments the Income tab lists. */
    private const PAYMENT_ROWS = 50;

    /** A longer range is pulled in — it keeps the Revenue sum and History list cheap. */
    private const MAX_SPAN_DAYS = 366;

    /** History rows shown; the header total still counts every row in range. */
    private const HISTORY_LIMIT = 100;

    /** Deep links keep their tab, range and History filters (?tab=due, ?tab=history&kind=bill&room=12). */
    protected $queryString = [
        'tab' => ['except' => 'revenue'],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'kind' => ['except' => ''],
        'room' => ['except' => ''],
    ];

    public string $tab = 'revenue';

    /** Y-m-d, inclusive. Revenue and History only. */
    public string $from = '';

    public string $to = '';

    /** History: 'bill' or a property_utility id; '' = the property's first meter. */
    public string $kind = '';

    /** History: a unit id; '' = every room. */
    public string $room = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'revenue';
        }

        $this->normaliseRange();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    /** 'month' | 'last' | 'quarter' | 'year' — anything else is this month. */
    public function setRange(string $preset): void
    {
        [$from, $to] = self::presetRange($preset);

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->normaliseRange($property);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function presetRange(string $preset): array
    {
        $now = now();

        return match ($preset) {
            'last' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /** The preset pill the current range equals, or null for a custom range. */
    private function activePreset(): ?string
    {
        foreach (['month', 'last', 'quarter', 'year'] as $preset) {
            [$from, $to] = self::presetRange($preset);

            if ($this->from === $from->toDateString() && $this->to === $to->toDateString()) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Make from/to real, ordered and not absurdly long. When one end is edited
     * past the other, the other end follows rather than the two silently
     * swapping under the landlord's thumb.
     */
    private function normaliseRange(?string $edited = null): void
    {
        $from = $this->parseDate($this->from) ?? now()->startOfMonth();
        $to = $this->parseDate($this->to) ?? now()->endOfMonth();

        if ($from->gt($to)) {
            if ($edited === 'to') {
                $from = $to->copy();
            } else {
                $to = $from->copy();
            }
        }

        if ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
            if ($edited === 'to') {
                $from = $to->copy()->subDays(self::MAX_SPAN_DAYS);
            } else {
                $to = $from->copy()->addDays(self::MAX_SPAN_DAYS);
            }
        }

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    private function parseDate(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        // "2026-02-31" parses to 3 March — reject anything that didn't round-trip.
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    // --- Tabs ---

    /** @return array<string, mixed> */
    private function revenue(int $propertyId, string $currency): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();
        $amount = fn (Payment $payment) => (float) ($currency === 'KHR' ? $payment->amount_khr : $payment->amount_usd);

        $payments = $this->paymentsBetween($propertyId, $from, $to);
        $total = (float) $payments->sum($amount);

        // The change against the stretch of the same length just before this one. Only
        // worth showing when that stretch took money: there is no percentage of nothing.
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $before = (float) $this->paymentsBetween($propertyId, $from->copy()->subDays($days), $from->copy()->subSecond())->sum($amount);

        return [
            'total' => $total,
            'count' => $payments->count(),
            'trend' => $before > 0 ? (int) round(($total - $before) / $before * 100) : null,
            // Newest first; the screen shows the latest few, not a ledger.
            'payments' => $payments->sortByDesc('paid_at')->take(self::PAYMENT_ROWS)->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'tenant' => $payment->invoice?->rental?->occupant_name ?? '—',
                'room' => $payment->invoice?->rental?->unit?->room_number ?? '—',
                'date' => $payment->paid_at?->translatedFormat('d M'),
                'method' => $payment->method?->getLabel(),
                'amount' => $amount($payment),
            ])->values(),
        ];
    }

    /** @return Collection<int, Payment> */
    private function paymentsBetween(int $propertyId, Carbon $from, Carbon $to): Collection
    {
        return Payment::query()
            ->whereBetween('paid_at', [$from, $to])
            // Payment has no landlord scope of its own — it is scoped through its invoice's.
            ->whereHas('invoice', fn ($q) => $q->where('property_id', $propertyId))
            ->with('invoice.rental.unit')
            ->get();
    }

    /** @return array<string, mixed> */
    private function due(int $propertyId): array
    {
        $rows = RoomDueService::forProperty($propertyId)->values();

        return [
            'total' => (float) $rows->sum('balance'),
            'overdueRooms' => $rows->where('daysLate', '>', 0)->count(),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function occupancy(int $propertyId): array
    {
        $rooms = Unit::query()
            ->with('activeRental')
            ->where('property_id', $propertyId)
            ->orderBy('room_number')
            ->get();

        $count = fn (UnitStatus ...$statuses) => $rooms->whereIn('status', $statuses)->count();
        $occupied = $count(UnitStatus::Occupied);

        return [
            'total' => $rooms->count(),
            'occupied' => $occupied,
            'available' => $count(UnitStatus::Available),
            'other' => $count(UnitStatus::Maintenance, UnitStatus::Unavailable),
            'percent' => $rooms->isEmpty() ? 0 : (int) round($occupied / $rooms->count() * 100),
            // Rooms that need attention (vacant, under repair) first, then the let ones, each by room number.
            'rooms' => $rooms->sort(fn (Unit $a, Unit $b) => (($a->status === UnitStatus::Occupied) <=> ($b->status === UnitStatus::Occupied))
                ?: strnatcasecmp((string) $a->room_number, (string) $b->room_number))->values(),
        ];
    }

    /**
     * @param  Collection<int, PropertyUtility>  $meters
     * @return array<string, mixed>
     */
    private function history(int $propertyId, Collection $meters, string $kind, ?int $roomId): array
    {
        if ($kind === 'bill') {
            $query = Invoice::query()
                ->where('property_id', $propertyId)
                ->whereBetween('issue_date', [$this->from, $this->to])
                ->when($roomId, fn ($q) => $q->whereHas('rental', fn ($rq) => $rq->where('unit_id', $roomId)));

            return [
                'kind' => 'bill',
                'count' => (clone $query)->count(),
                'total' => (float) (clone $query)->where('payment_status', '!=', InvoiceStatus::Cancelled->value)->sum('amount_due'),
                'rows' => $query->with('rental.unit')->orderByDesc('issue_date')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get(),
            ];
        }

        $utility = $meters->firstWhere('id', (int) $kind);

        $query = UtilityUsage::query()
            ->where('property_utility_id', $utility?->id)
            ->whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
            ->whereBetween('reading_date', [$this->from, $this->to])
            ->when($roomId, fn ($q) => $q->where('unit_id', $roomId));

        return [
            'kind' => 'meter',
            'utility' => $utility,
            'count' => (clone $query)->count(),
            'total' => (float) (clone $query)->sum('amount_used'),
            'rows' => $query->with('unit')->orderByDesc('reading_date')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get(),
        ];
    }

    public function render()
    {
        $propertyId = ActiveProperty::id();
        $currency = Money::activeCurrency();
        $data = [];
        $meters = collect();
        $rooms = collect();
        $kind = 'bill';
        $roomId = null;

        if ($propertyId) {
            if ($this->tab === 'history') {
                $meters = PropertyUtility::query()
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->filter(fn (PropertyUtility $utility) => $utility->requiresReading())
                    ->values();

                $rooms = Unit::query()->where('property_id', $propertyId)->orderBy('room_number')->get(['id', 'room_number']);

                // A stale or tampered kind / room falls back instead of querying someone else's id.
                $kind = $this->kind === 'bill' || $meters->contains('id', (int) $this->kind)
                    ? $this->kind
                    : (string) ($meters->first()?->id ?? 'bill');
                $roomId = $rooms->contains('id', (int) $this->room) ? (int) $this->room : null;
            }

            $data = match ($this->tab) {
                'due' => $this->due($propertyId),
                'occupancy' => $this->occupancy($propertyId),
                'history' => $this->history($propertyId, $meters, $kind, $roomId),
                default => $this->revenue($propertyId, $currency),
            };
        }

        return view('livewire.m2.dashboard', [
            'property' => ActiveProperty::model(),
            'propertyId' => $propertyId,
            'currency' => $currency,
            'data' => $data,
            'preset' => $this->activePreset(),
            'meters' => $meters,
            'rooms' => $rooms,
            // Not 'kind': a public property of the same name would override it in the view.
            'historyKind' => $kind,
            'roomId' => $roomId,
        ])->title(__('Dashboard'));
    }
}
