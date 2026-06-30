<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Resources\PropertyResource;
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
                ->url(fn ($record) => \App\Filament\Resources\LandlordResource::getUrl('view', ['record' => $record->landlord_id], panel: 'admin')),
            Actions\Action::make('monthlyBilling')
                ->label(__('Monthly billing'))
                ->icon('heroicon-o-calendar-days')
                ->url(fn () => MonthlyBilling::getUrl()),
            Actions\EditAction::make()->label(__('Edit property'))->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()
                ->schema([
                    TextEntry::make('name')->label('Property')->weight('bold')->size('lg'),
                    TextEntry::make('property_type')->badge(),
                    TextEntry::make('address')
                        ->label('Address')
                        ->state(fn ($record) => collect([$record->address_line, $record->village, $record->commune, $record->district, $record->city])->filter()->implode(', ') ?: '—'),
                    TextEntry::make('landlord.name')->label('Owner')->visible(fn () => auth()->user()?->isPlatformStaff()),
                ])->columns(2),

            Section::make('At a glance')
                ->schema([
                    TextEntry::make('rooms')->label('Rooms')
                        ->state(fn ($record) => $record->units()->count()),
                    TextEntry::make('occupied')->label('Occupied')
                        ->state(fn ($record) => $record->units()->where('status', UnitStatus::Occupied->value)->count()),
                    TextEntry::make('utilities')->label('Active utilities')
                        ->state(fn ($record) => $record->propertyUtilities()->where('is_active', true)->count()),
                    TextEntry::make('outstanding')->label('Outstanding')
                        ->state(fn ($record) => '$'.number_format((float) $record->invoices()
                            ->whereIn('payment_status', [InvoiceStatus::Pending->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value])
                            ->selectRaw('COALESCE(SUM(amount_due - amount_paid),0) as b')->value('b'), 2))
                        ->color('warning'),
                ])->columns(4),
        ]);
    }
}
