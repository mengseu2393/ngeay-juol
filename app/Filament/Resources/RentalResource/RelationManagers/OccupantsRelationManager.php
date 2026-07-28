<?php

namespace App\Filament\Resources\RentalResource\RelationManagers;

use App\Enums\OccupantRole;
use App\Models\RentalOccupant;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manages the list of occupants (primary tenant, co-tenants, dependents) within
 * a single rental. The primary occupant is created automatically from the rental's
 * occupant fields; additional people can be added here.
 */
class OccupantsRelationManager extends RelationManager
{
    protected static string $relationship = 'occupants';

    protected static ?string $title = 'Occupants';

    protected static ?string $recordTitleAttribute = 'occupant_name';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Occupant Details'))
                ->schema([
                    Forms\Components\Select::make('role')
                        ->label(__('Role'))
                        ->options(OccupantRole::class)
                        ->default(OccupantRole::CoTenant)
                        ->required()
                        ->helperText(__('Primary = responsible for rent. Co-Tenant = roommate. Dependent = family member.')),

                    Forms\Components\TextInput::make('occupant_name')
                        ->label(__('Full name'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('occupant_phone')
                        ->label(__('Phone'))
                        ->tel(),

                    Forms\Components\Select::make('occupant_gender')
                        ->label(__('Gender'))
                        ->options([
                            'male'   => __('Male'),
                            'female' => __('Female'),
                            'other'  => __('Other'),
                        ])
                        ->placeholder(__('Select gender')),

                    Forms\Components\DatePicker::make('occupant_dob')
                        ->label(__('Date of birth'))
                        ->maxDate(now()),

                    Forms\Components\TextInput::make('occupant_nationality')
                        ->label(__('Nationality'))
                        ->placeholder(__('e.g. Khmer, Vietnamese')),

                    Forms\Components\TextInput::make('occupant_workplace')
                        ->label(__('Workplace'))
                        ->placeholder(__('e.g. company name')),

                    Forms\Components\TextInput::make('occupant_id_card')
                        ->label(__('ID card number')),

                    Forms\Components\TextInput::make('occupant_address')
                        ->label(__('Address'))
                        ->columnSpanFull(),

                    SpatieMediaLibraryFileUpload::make('id_cards')
                        ->collection('id_cards')
                        ->label(__('ID card photos'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(4)
                        ->helperText(__('Front/back of national ID, passport, etc.'))
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make(__('Emergency & Guarantor'))
                ->collapsed()
                ->schema([
                    Forms\Components\Fieldset::make(__('Emergency contact'))
                        ->schema([
                            Forms\Components\TextInput::make('emergency_contact_name')->label(__('Name')),
                            Forms\Components\TextInput::make('emergency_contact_phone')->label(__('Phone'))->tel(),
                            Forms\Components\TextInput::make('emergency_contact_relationship')
                                ->label(__('Relationship'))
                                ->placeholder(__('e.g. mother, brother')),
                        ])->columns(3),

                    Forms\Components\Fieldset::make(__('Guarantor'))
                        ->schema([
                            Forms\Components\TextInput::make('guarantor_name')->label(__('Name')),
                            Forms\Components\TextInput::make('guarantor_phone')->label(__('Phone'))->tel(),
                            Forms\Components\TextInput::make('guarantor_id_number')->label(__('ID number')),
                            Forms\Components\TextInput::make('guarantor_address')->label(__('Address')),
                        ])->columns(2),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('Notes'))
                        ->placeholder(__('Private notes about this occupant'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('occupant_name')
            ->defaultSort('role')
            ->columns([
                Tables\Columns\TextColumn::make('occupant_name')
                    ->label(__('Name'))
                    ->searchable()
                    ->weight(fn (RentalOccupant $record) => $record->isPrimary() ? 'bold' : 'normal'),

                Tables\Columns\TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->color(fn (OccupantRole $state) => match ($state) {
                        OccupantRole::Primary   => 'success',
                        OccupantRole::CoTenant  => 'info',
                        OccupantRole::Dependent => 'gray',
                    }),

                Tables\Columns\TextColumn::make('occupant_phone')
                    ->label(__('Phone'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('occupant_id_card')
                    ->label(__('ID card'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('occupant_gender')
                    ->label(__('Gender'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Add occupant'))
                    ->before(function (Tables\Actions\CreateAction $action) {
                        // Enforce max_occupants capacity
                        $rental = $this->getOwnerRecord();
                        $unit = $rental->unit;
                        $maxOccupants = $unit?->max_occupants ?? 1;
                        $currentCount = $rental->occupants()->count();

                        if ($currentCount >= $maxOccupants) {
                            Notification::make()
                                ->title(__('Room is at capacity'))
                                ->body(__('This room allows a maximum of :max occupants. Current: :current.', [
                                    'max'     => $maxOccupants,
                                    'current' => $currentCount,
                                ]))
                                ->warning()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->before(function (RentalOccupant $record, Tables\Actions\DeleteAction $action) {
                            // Prevent deleting the last primary occupant
                            if ($record->isPrimary()) {
                                $otherPrimary = $this->getOwnerRecord()
                                    ->occupants()
                                    ->where('id', '!=', $record->id)
                                    ->where('role', 'primary')
                                    ->exists();

                                if (! $otherPrimary) {
                                    Notification::make()
                                        ->title(__('Cannot remove primary occupant'))
                                        ->body(__('A rental must have at least one primary occupant. Change another occupant\'s role to primary first.'))
                                        ->danger()
                                        ->send();

                                    $action->cancel();
                                }
                            }
                        }),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }
}
