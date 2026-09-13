<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRentals extends ListRecords
{
    protected static string $resource = RentalResource::class;

    /** Whether this page was reached from a Simple Mode link (?from=simple). */
    public bool $fromSimpleMode = false;

    public function mount(): void
    {
        parent::mount();

        $this->fromSimpleMode = request()->query('from') === 'simple';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToSimpleMode')
                ->label(__('Back to Simple Mode'))
                ->icon('heroicon-o-device-phone-mobile')
                ->color('gray')
                ->url(route('filament.landlord.pages.simple'))
                ->visible(fn () => $this->fromSimpleMode),
            Actions\CreateAction::make()
                ->url(fn () => static::getResource()::getUrl('create', $this->fromSimpleMode ? ['from' => 'simple'] : [])),
        ];
    }
}
