<?php

namespace App\Filament\Resources\UnitResource\Pages;

use App\Enums\ReadingType;
use App\Filament\Resources\UnitResource;
use App\Models\UtilityUsage;
use App\Services\RoomDetailsService;
use App\Services\RoomMeterReadingsService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EditUnit extends EditRecord
{
    protected static string $resource = UnitResource::class;

    /** Same write as the /m Edit room screen. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(RoomDetailsService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('meterReadings')
                ->label(__('Meter readings'))
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn () => auth()->user()?->can('create', UtilityUsage::class))
                ->modalWidth('lg')
                ->modalHeading(fn () => __('Meter readings — Room :room', ['room' => $this->record->room_number]))
                ->modalSubmitActionLabel(__('Save readings'))
                ->form(function (): array {
                    $record = $this->record;
                    $utilities = UnitResource::meterUtilitiesFor($record);

                    if ($utilities->isEmpty()) {
                        return [
                            Forms\Components\Placeholder::make('no_utilities')
                                ->label('')
                                ->content(__('No metered utilities are set up for this property yet. Add them under Utilities first, then record readings here.')),
                        ];
                    }

                    $schema = [
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('reading_date')
                                ->label(__('Reading date'))->default(now())->required()
                                ->maxDate(now()),
                            Forms\Components\Select::make('reading_type')
                                ->label(__('Reading type'))
                                ->options(ReadingType::class)
                                ->default(ReadingType::Actual->value)->required(),
                        ]),
                    ];

                    foreach ($utilities as $utility) {
                        $uom = $utility->unit_of_measure;
                        $help = UnitResource::readingHint($record->getKey(), $utility->getKey(), $uom);

                        $schema[] = Forms\Components\TextInput::make("meters.{$utility->getKey()}")
                            ->label($utility->name.($uom ? " ({$uom})" : ''))
                            ->numeric()->minValue(0)->step('0.001')
                            ->maxValue(999999999)
                            ->suffix($uom)
                            ->helperText($help);
                    }

                    return $schema;
                })
                ->action(function (array $data): void {
                    $record = $this->record;
                    $saved = app(RoomMeterReadingsService::class)->save(
                        $record,
                        Carbon::parse($data['reading_date'] ?? now())->toDateString(),
                        (int) ($data['reading_type'] ?? ReadingType::Actual->value),
                        $data['meters'] ?? [],
                    );

                    Notification::make()
                        ->title($saved
                            ? __(':count meter reading(s) saved for room :room', ['count' => $saved, 'room' => $record->room_number])
                            : __('No readings entered.'))
                        ->{$saved ? 'success' : 'warning'}()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
