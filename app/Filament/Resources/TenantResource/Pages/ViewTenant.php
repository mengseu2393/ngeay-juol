<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\RentalResource;
use App\Filament\Resources\TenantResource;
use App\Models\Rental;
use App\Models\User;
use App\Support\Money;
use App\Support\Receivables;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * One tenant, whole: how to reach them, where they live now, what they owe.
 * The history across rooms and the invoice ledger are the two relation-manager
 * tabs below — a tenant who moved rooms is one person here, not two tenancies.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openTenancy')
                ->label(__('Open tenancy'))
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible(fn (): bool => $this->currentTenancy() !== null)
                ->url(fn (): string => RentalResource::getUrl('view', ['record' => $this->currentTenancy()])),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('Contact'))
                ->schema([
                    TextEntry::make('name')->label(__('Tenant'))->weight('bold')->size('lg'),
                    TextEntry::make('phone_number')->label(__('Phone'))->copyable()->placeholder('—'),
                    TextEntry::make('status')->label(__('Account'))->badge(),
                    TextEntry::make('username')->label(__('Portal login'))->copyable()->placeholder(__('No portal login')),
                    TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
                    TextEntry::make('tenantProfile.id_card_number')->label(__('ID card number'))->placeholder('—'),
                    TextEntry::make('tenantProfile.occupation')->label(__('Occupation'))->placeholder('—'),
                    TextEntry::make('tenantProfile.emergency_contact_name')->label(__('Emergency contact'))->placeholder('—'),
                    TextEntry::make('tenantProfile.emergency_contact_phone')->label(__('Emergency phone'))->placeholder('—'),
                ])->columns(3),

            Section::make(__('Current tenancy'))
                ->description(fn (): ?string => $this->currentTenancy() === null
                    ? __('This tenant has no tenancy on record with you.')
                    : null)
                ->schema([
                    TextEntry::make('current_room')
                        ->label(__('Room'))
                        ->state(fn (): ?string => $this->currentTenancy()?->unit?->room_number)
                        ->placeholder('—'),
                    TextEntry::make('current_property')
                        ->label(__('Property'))
                        ->state(fn (): ?string => $this->currentTenancy()?->property?->name)
                        ->placeholder('—'),
                    TextEntry::make('current_status')
                        ->label(__('Status'))
                        ->badge()
                        ->state(fn () => $this->currentTenancy()?->status)
                        ->placeholder('—'),
                    TextEntry::make('current_rent')
                        ->label(__('Monthly rent'))
                        ->state(fn (): ?string => ($rental = $this->currentTenancy()) === null
                            ? null
                            : Money::formatForRecord($rental->monthly_rent, $rental))
                        ->placeholder('—'),
                    TextEntry::make('current_start')
                        ->label(__('Moved in'))
                        ->date()
                        ->state(fn () => $this->currentTenancy()?->start_date)
                        ->placeholder('—'),
                    TextEntry::make('current_end')
                        ->label(__('Lease ends'))
                        ->date()
                        ->state(fn () => $this->currentTenancy()?->end_date)
                        ->placeholder('—'),
                    TextEntry::make('outstanding')
                        ->label(__('Outstanding'))
                        ->state(fn (): string => Receivables::format(TenantResource::openBalance($this->tenant())))
                        ->color(fn (): string => TenantResource::owesMoney($this->tenant()) ? 'danger' : 'gray')
                        ->weight('bold'),
                ])->columns(4),
        ]);
    }

    private function tenant(): User
    {
        /** @var User $record */
        $record = $this->getRecord();

        return $record;
    }

    private function currentTenancy(): ?Rental
    {
        return TenantResource::currentTenancy($this->tenant());
    }
}
