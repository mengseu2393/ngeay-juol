<?php

namespace App\Filament\Resources\PropertyUtilityResource\Pages;

use App\Filament\Resources\PropertyUtilityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPropertyUtilities extends ListRecords
{
    protected static string $resource = PropertyUtilityResource::class;

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
            Actions\CreateAction::make()->label(__('Add utility')),
        ];
    }
}
