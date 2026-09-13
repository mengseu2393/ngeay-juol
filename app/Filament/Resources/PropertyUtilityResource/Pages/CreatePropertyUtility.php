<?php

namespace App\Filament\Resources\PropertyUtilityResource\Pages;

use App\Filament\Resources\PropertyUtilityResource;
use App\Support\ActiveProperty;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePropertyUtility extends CreateRecord
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
        ];
    }

    /**
     * The form's property_id is a hidden, defaulted field whenever a property is
     * active — but hidden defaults don't reliably dehydrate, so inject the active
     * property here. When no property is active the field is shown + required, so
     * the submitted value is kept as-is.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['property_id'])) {
            $data['property_id'] = ActiveProperty::id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->fromSimpleMode
            ? static::getResource()::getUrl('index', ['from' => 'simple'])
            : static::getResource()::getUrl('index');
    }
}
