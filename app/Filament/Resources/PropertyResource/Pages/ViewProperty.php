<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Widgets\PropertySetupChecklistWidget;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewProperty extends ViewRecord
{
    protected static string $resource = PropertyResource::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    /** Keep the Overview clean — relation managers live in their own sub-nav tabs. */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * The setup checklist sits above the infolist: a half-configured property is
     * exactly the one whose "Rooms 0 / Active utilities 0" glance is confusing.
     * It hides itself once every step is done.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PropertySetupChecklistWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /**
     * This page can show a property that is not the one selected in the sidebar
     * switcher, so the widget is told which record it is looking at rather than
     * left to read the active-property context.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['propertyId' => $this->getRecord()->getKey()];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Platform staff arrive here by drilling in from /admin/landlords;
            // give them a visible way back to that landlord's admin page.
            Actions\Action::make('backToLandlord')
                ->label(__('Back to landlord'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn ($record) => auth()->user()?->isPlatformStaff() ?? false)
                ->url(fn ($record) => LandlordResource::getUrl('view', ['record' => $record->landlord_id], panel: 'admin')),
            Actions\Action::make('monthlyBilling')
                ->label(__('Monthly billing'))
                ->icon('heroicon-o-calendar-days')
                ->visible(fn () => MonthlyBilling::canAccess())
                ->action(function ($record) {
                    ActiveProperty::set($record->getKey());

                    return redirect(MonthlyBilling::getUrl());
                }),
            Actions\EditAction::make()->label(__('Edit property'))->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()
                ->schema([
                    TextEntry::make('name')->label(__('Property'))->weight('bold')->size('lg'),
                    TextEntry::make('property_type')->badge(),
                    TextEntry::make('address')
                        ->label(__('Address'))
                        ->state(fn ($record) => collect([$record->address_line, $record->village, $record->commune, $record->district, $record->city])->filter()->implode(', ') ?: '—'),
                    TextEntry::make('landlord.name')->label(__('Owner'))->visible(fn () => auth()->user()?->isPlatformStaff()),
                ])->columns(2),

            Section::make(__('At a glance'))
                ->schema([
                    TextEntry::make('rooms')->label(__('Rooms'))
                        ->state(fn ($record) => $record->units()->count()),
                    TextEntry::make('occupied')->label(__('Occupied'))
                        ->state(fn ($record) => $record->units()->where('status', UnitStatus::Occupied->value)->count()),
                    TextEntry::make('utilities')->label(__('Active utilities'))
                        ->state(fn ($record) => $record->propertyUtilities()->where('is_active', true)->count()),
                    TextEntry::make('outstanding')->label(__('Outstanding'))
                        ->state(function ($record) {
                            $invoices = $record->invoices()
                                ->whereIn('payment_status', [
                                    InvoiceStatus::Pending->value,
                                    InvoiceStatus::Partial->value,
                                    InvoiceStatus::Overdue->value,
                                ])
                                ->get();

                            $usdTotal = 0.0;
                            $khrTotal = 0.0;

                            foreach ($invoices as $invoice) {
                                $usdTotal += $invoice->balance_usd;
                                $khrTotal += $invoice->balance_khr;
                            }

                            $usdFormatted = Money::format($usdTotal, 'USD');
                            $khrFormatted = Money::format($khrTotal, 'KHR');

                            return "{$usdFormatted} / {$khrFormatted}";
                        })
                        ->color('warning'),
                ])->columns(4),
        ]);
    }
}
