<?php

namespace App\Filament\Resources\PropertyUtilityResource\RelationManagers;

use App\Enums\BillingType;
use App\Enums\MeterScope;
use App\Enums\MeterStatus;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Services\MeterReadingResolver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The physical devices behind one utility: install a meter with the index it
 * actually shows (almost never 0), see its current index, and replace it when it
 * is swapped — which retires the old device with a final reading and opens the
 * new one, instead of pretending the counter jumped backwards.
 *
 * Hidden entirely when config('utilities.meters') is off, so the feature can be
 * withdrawn without leaving dead UI behind.
 */
class MetersRelationManager extends RelationManager
{
    protected static string $relationship = 'meters';

    protected static ?string $title = 'Meters';

    protected static ?string $icon = 'heroicon-o-cpu-chip';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return (bool) config('utilities.meters', true)
            && $ownerRecord->billing_type === BillingType::Metered;
    }

    public function form(Form $form): Form
    {
        $utility = $this->getOwnerRecord();

        return $form->schema([
            Forms\Components\Select::make('unit_id')
                ->label(__('Room'))
                ->options(fn () => Unit::where('property_id', $utility->property_id)
                    ->orderBy('room_number')
                    ->pluck('room_number', 'id'))
                ->searchable()
                ->required()
                ->rules([
                    // One live device per room, or "the active meter" is ambiguous.
                    fn (?UtilityMeter $record) => function (string $attribute, $value, \Closure $fail) use ($utility, $record) {
                        $exists = UtilityMeter::query()
                            ->active()
                            ->forRoom((int) $value, $utility->getKey())
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($exists) {
                            $fail(__('This room already has an active meter — use “Replace meter” instead.'));
                        }
                    },
                ]),

            Forms\Components\TextInput::make('serial')
                ->label(__('Serial / device number'))
                ->maxLength(255)
                ->helperText(__('Printed on the meter. Shown on invoices so a tenant can verify the reading.')),

            Forms\Components\DatePicker::make('installed_on')
                ->label(__('Installed on'))
                ->default(now())
                ->required()
                ->maxDate(now()),

            Forms\Components\TextInput::make('installed_reading')
                ->label(__('Reading when installed'))
                ->numeric()
                ->minValue(0)
                ->step('0.001')
                ->maxValue(999999999)
                ->default(0)
                ->required()
                ->suffix($utility->unit_of_measure)
                ->helperText(__('The number the meter shows today — not 0 unless it is brand new. Billing measures the first cycle from here.')),

            Forms\Components\TextInput::make('digits')
                ->label(__('Digits (optional)'))
                ->numeric()
                ->minValue(3)
                ->maxValue(12)
                ->helperText(__('Set it and a rollover (99999 → 00001) counts as usage instead of a negative.')),

            Forms\Components\TextInput::make('multiplier')
                ->label(__('Multiplier'))
                ->numeric()
                ->default(1)
                ->step('0.0001')
                ->minValue(0.0001)
                ->helperText(__('CT ratio. Leave at 1 for a direct-reading meter.')),

            Forms\Components\Hidden::make('scope')->default(MeterScope::Unit->value),
            Forms\Components\Hidden::make('created_by_id')->default(fn () => auth()->id()),

            Forms\Components\Textarea::make('notes')
                ->label(__('Notes'))
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $uom = $this->getOwnerRecord()->unit_of_measure;

        return $table
            ->recordTitleAttribute('serial')
            ->defaultSort('unit_id')
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Room'))
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('serial')
                    ->label(__('Serial'))
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('installed_reading')
                    ->label(__('Installed at'))
                    ->formatStateUsing(fn ($state) => static::trim($state).($uom ? ' '.$uom : ''))
                    ->description(fn (UtilityMeter $record) => $record->installed_on?->format('d M Y')),
                Tables\Columns\TextColumn::make('current_index')
                    ->label(__('Current index'))
                    ->state(fn (UtilityMeter $record) => $record->isActive()
                        ? static::trim($record->currentIndex()).($uom ? ' '.$uom : '')
                        : static::trim($record->final_reading).($uom ? ' '.$uom : ''))
                    ->description(fn (UtilityMeter $record) => $record->isActive()
                        ? null
                        : __('Final · :date', ['date' => $record->removed_on?->format('d M Y')])),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('usages_count')
                    ->label(__('Readings'))
                    ->counts('usages')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(MeterStatus::options()),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Install meter'))
                    ->modalHeading(__('Install meter')),
            ])
            ->actions([
                $this->replaceAction(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (UtilityMeter $record) => $record->isActive()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (UtilityMeter $record) => $record->usages()->doesntExist()),
            ])
            ->emptyStateHeading(__('No meters recorded'))
            ->emptyStateDescription(__('Install one per room with the index it currently shows, or run `php artisan utilities:backfill-meters` to build them from the readings you already have.'));
    }

    protected function replaceAction(): Tables\Actions\Action
    {
        $uom = $this->getOwnerRecord()->unit_of_measure;

        return Tables\Actions\Action::make('replace')
            ->label(__('Replace meter'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (UtilityMeter $record) => $record->isActive())
            ->modalHeading(fn (UtilityMeter $record) => __('Replace meter — :meter', ['meter' => $record->label()]))
            ->modalDescription(__('The old meter is retired with its final reading and kept for history. The new one starts its own count, so nothing is billed across the two.'))
            ->modalSubmitActionLabel(__('Replace'))
            ->form(fn (UtilityMeter $record) => [
                Forms\Components\DatePicker::make('date')
                    ->label(__('Replacement date'))
                    ->default(now())
                    ->required()
                    ->minDate($record->installed_on)
                    ->maxDate(now()),
                Forms\Components\TextInput::make('final_reading')
                    ->label(__('Final reading on the OLD meter'))
                    ->numeric()->minValue(0)->step('0.001')->maxValue(999999999)
                    ->default(fn () => static::trim($record->currentIndex()))
                    ->required()
                    ->suffix($uom)
                    ->helperText(__('Defaults to its last billed index — raise it to record what it clocked up since.')),
                Forms\Components\TextInput::make('installed_reading')
                    ->label(__('Reading on the NEW meter'))
                    ->numeric()->minValue(0)->step('0.001')->maxValue(999999999)
                    ->default(0)
                    ->required()
                    ->suffix($uom)
                    ->helperText(__('0 for a brand-new device; otherwise whatever it shows.')),
                Forms\Components\TextInput::make('serial')
                    ->label(__('Serial of the new meter'))
                    ->maxLength(255),
            ])
            ->action(function (UtilityMeter $record, array $data): void {
                $new = app(MeterReadingResolver::class)->replace(
                    $record,
                    $data['date'],
                    (float) $data['installed_reading'],
                    (float) $data['final_reading'],
                    ['serial' => $data['serial'] ?: null],
                );

                Notification::make()
                    ->success()
                    ->title(__('Meter replaced'))
                    ->body(__('Next invoice for this room measures from :value.', [
                        'value' => static::trim($new->installed_reading),
                    ]))
                    ->send();
            });
    }

    /** "1251", "11", "1.5" — drops the decimal noise from decimal(…,3). */
    protected static function trim($value): string
    {
        $formatted = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
