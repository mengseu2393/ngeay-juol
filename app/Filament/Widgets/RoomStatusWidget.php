<?php

namespace App\Filament\Widgets;

use App\Enums\UnitStatus;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Unit;
use Filament\Widgets\ChartWidget;

class RoomStatusWidget extends ChartWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 2;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string
    {
        return __('Room Status');
    }

    protected function getData(): array
    {
        $counts = $this->scopeToActiveProperty(Unit::query())
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $labels = [];
        $data = [];
        $backgroundColor = [];

        foreach (UnitStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;

            $labels[] = $status->getLabel();
            $data[] = $count;
            $backgroundColor[] = match ($status) {
                UnitStatus::Available => '#10b981',   // Green
                UnitStatus::Occupied => '#3b82f6',    // Blue
                UnitStatus::Maintenance => '#f59e0b', // Yellow/Warning
                UnitStatus::Unavailable => '#6b7280', // Gray
            };
        }

        return [
            'datasets' => [
                [
                    'label' => __('Rooms'),
                    'data' => $data,
                    'backgroundColor' => $backgroundColor,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
