<?php

namespace App\Filament\Resources\PropertyUtilityResource\RelationManagers;

use App\Filament\Tables\RowActionGroup;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\ChargeRuleResolver;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChargeRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'chargeRules';

    protected static ?string $title = 'Charge Applicability & Overrides';

    public function form(Form $form): Form
    {
        $owner = $this->getOwnerRecord();
        $propertyId = match (true) {
            $owner instanceof PropertyUtility => $owner->property_id,
            $owner instanceof Unit => $owner->property_id,
            $owner instanceof Rental => $owner->property_id,
            default => null,
        };

        $schema = [
            Forms\Components\Hidden::make('created_by_id')->default(fn () => auth()->id()),
            Forms\Components\Hidden::make('property_id')->default($propertyId),
            Forms\Components\Hidden::make('landlord_id')->default(fn () => $owner->resolveLandlordId() ?? $owner->landlord_id ?? null),
        ];

        // 1. If owner is PropertyUtility, we select the scope:
        if ($owner instanceof PropertyUtility) {
            $schema[] = Forms\Components\Hidden::make('property_utility_id')->default($owner->getKey());

            $schema[] = Forms\Components\Select::make('scope_type')
                ->label(__('Applicable Scope'))
                ->options([
                    'property' => __('Whole property'),
                    'unit' => __('Specific Room'),
                    'rental' => __('Specific Rental / Tenant'),
                ])
                ->default('property')
                ->required()
                ->live();

            $schema[] = Forms\Components\Select::make('scope_id')
                ->label(__('Select target'))
                ->options(function (Forms\Get $get) use ($propertyId) {
                    $type = $get('scope_type');
                    if ($type === 'unit') {
                        return Unit::where('property_id', $propertyId)->pluck('room_number', 'id');
                    }
                    if ($type === 'rental') {
                        return Rental::whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
                            ->with('unit')->get()
                            ->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.($r->unit?->room_number ?? '').' ('.($r->occupant_name ?? '').')']);
                    }

                    return [$propertyId => __('Whole property')];
                })
                ->visible(fn (Forms\Get $get) => in_array($get('scope_type'), ['unit', 'rental']))
                ->required(fn (Forms\Get $get) => in_array($get('scope_type'), ['unit', 'rental']))
                ->searchable()
                ->live();
        } else {
            // Owner is Unit or Rental (Room or Contract)
            $scopeType = $owner instanceof Unit ? 'unit' : 'rental';
            $schema[] = Forms\Components\Hidden::make('scope_type')->default($scopeType);
            $schema[] = Forms\Components\Hidden::make('scope_id')->default($owner->getKey());

            // User selects which Utility/Charge this rule overrides
            $schema[] = Forms\Components\Select::make('property_utility_id')
                ->label(__('Utility / Charge'))
                ->options(fn () => PropertyUtility::where('property_id', $propertyId)->pluck('name', 'id'))
                ->searchable()
                ->required();
        }

        // Common fields for all rule scopes:
        $schema[] = Forms\Components\Select::make('state')
            ->label(__('Applicability / State'))
            ->options([
                'normal' => ChargeRuleResolver::stateLabel('normal'),
                'free' => ChargeRuleResolver::stateLabel('free'),
                'waived' => ChargeRuleResolver::stateLabel('waived'),
                'not_applicable' => ChargeRuleResolver::stateLabel('not_applicable'),
                'skipped_this_cycle' => ChargeRuleResolver::stateLabel('skipped_this_cycle'),
                'custom' => ChargeRuleResolver::stateLabel('custom'),
            ])
            ->helperText(fn (Forms\Get $get) => ChargeRuleResolver::stateHelpText((string) $get('state')))
            ->default('normal')
            ->required()
            ->live();

        $schema[] = Forms\Components\TextInput::make('amount_override')
            ->label(__('Custom price'))
            ->numeric()
            ->visible(fn (Forms\Get $get) => $get('state') === 'custom')
            ->required(fn (Forms\Get $get) => $get('state') === 'custom');

        $schema[] = Forms\Components\Select::make('currency_override')
            ->label(__('Custom currency'))
            ->options([
                'USD' => 'USD ($)',
                'KHR' => 'KHR (៛)',
            ])
            ->visible(fn (Forms\Get $get) => $get('state') === 'custom')
            ->required(fn (Forms\Get $get) => $get('state') === 'custom');

        $schema[] = Forms\Components\DatePicker::make('effective_from')
            ->label(__('Effective from'))
            ->helperText(__('Optional: Start date for this rule.'));

        $schema[] = Forms\Components\DatePicker::make('effective_until')
            ->label(__('Effective until'))
            ->helperText(__('Optional: End date for this rule.'));

        $schema[] = Forms\Components\TextInput::make('reason')
            ->label(__('Reason / Note'))
            ->placeholder(__('e.g. No motorbike, maintenance issue'))
            ->columnSpanFull();

        return $form->schema($schema)->columns(2);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        $columns = [];

        // 1. If owner is PropertyUtility, we show the Scope Column
        if ($owner instanceof PropertyUtility) {
            $columns[] = Tables\Columns\TextColumn::make('scope_type')
                ->label(__('Scope'))
                ->formatStateUsing(fn ($state) => match ($state) {
                    'property' => __('Property-wide'),
                    'unit' => __('Room override'),
                    'rental' => __('Tenant override'),
                    default => $state,
                });

            $columns[] = Tables\Columns\TextColumn::make('scope_id')
                ->label(__('Target'))
                ->formatStateUsing(function ($state, $record) {
                    if ($record->scope_type === 'unit') {
                        return Unit::find($state)?->room_number ?? __('Room #').$state;
                    }
                    if ($record->scope_type === 'rental') {
                        $rental = Rental::find($state);

                        return $rental ? '#'.$rental->id.' ('.($rental->unit?->room_number ?? '').')' : __('Rental #').$state;
                    }

                    return __('Whole property');
                });
        } else {
            // Owner is Unit or Rental, show which Utility it applies to
            $columns[] = Tables\Columns\TextColumn::make('propertyUtility.name')
                ->label(__('Utility / Charge'));
        }

        // Common table columns
        $columns[] = Tables\Columns\TextColumn::make('state')
            ->label(__('State'))
            ->badge()
            ->color(fn ($state) => match ($state) {
                'normal' => 'gray',
                'free' => 'success',
                'waived' => 'warning',
                'not_applicable' => 'danger',
                'skipped_this_cycle' => 'gray',
                'custom' => 'info',
                default => 'gray',
            })
            ->formatStateUsing(fn ($state) => ChargeRuleResolver::stateLabel((string) $state));

        $columns[] = Tables\Columns\TextColumn::make('amount_override')
            ->label(__('Override Details'))
            ->state(function ($record) {
                if ($record->state === 'custom') {
                    return Money::format($record->amount_override, $record->currency_override);
                }

                return '—';
            });

        $columns[] = Tables\Columns\TextColumn::make('effective_from')
            ->label(__('Duration'))
            ->state(function ($record) {
                $from = $record->effective_from ? $record->effective_from->format('d M Y') : '—';
                $until = $record->effective_until ? $record->effective_until->format('d M Y') : '∞';

                return "{$from} → {$until}";
            });

        $columns[] = Tables\Columns\TextColumn::make('reason')
            ->label(__('Reason / Note'))
            ->placeholder('—');

        return $table
            ->columns($columns)
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data) use ($owner) {
                        if ($owner instanceof PropertyUtility) {
                            if ($data['scope_type'] === 'property') {
                                $data['scope_id'] = $owner->property_id;
                            }
                        } else {
                            $data['property_id'] = $owner->property_id;
                            $data['scope_type'] = $owner instanceof Unit ? 'unit' : 'rental';
                            $data['scope_id'] = $owner->getKey();
                        }
                        $data['created_by_id'] = auth()->id();
                        $data['landlord_id'] = $owner->resolveLandlordId() ?? $owner->landlord_id ?? null;

                        return $data;
                    }),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }
}
