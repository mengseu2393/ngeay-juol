<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Enums\InvoiceLineType;
use App\Filament\Tables\RowActionGroup;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Line items';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('line_type')
                ->options(InvoiceLineType::class)
                ->default(InvoiceLineType::AdHoc)
                ->required(),
            Forms\Components\TextInput::make('description')->required(),
            Forms\Components\TextInput::make('quantity')->numeric(),
            Forms\Components\TextInput::make('unit_price')->numeric()->prefix(fn () => Money::activeSymbol()),
            Forms\Components\TextInput::make('amount')->numeric()->prefix(fn () => Money::activeSymbol())->required(),
            Forms\Components\Toggle::make('is_waived'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('line_type')->badge(),
                Tables\Columns\TextColumn::make('description')->wrap(),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('unit_price')
                    ->formatStateUsing(fn ($state, $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state, $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('charge_state')
                    ->label(__('Charge state'))
                    ->badge()
                    ->formatStateUsing(function ($state, $record) {
                        return $record->resolvedChargeStateLabel();
                    })
                    ->color(function ($state, $record) {
                        return match ($record->resolvedChargeState()) {
                            'free' => 'success',
                            'waived' => 'warning',
                            'not_applicable', 'skipped_this_cycle' => 'danger',
                            'custom' => 'info',
                            default => 'gray',
                        };
                    }),
                Tables\Columns\TextColumn::make('charge_state_reason')
                    ->label(__('State reason'))
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('source_scope')
                    ->label(__('Source scope'))
                    ->state(fn ($record) => $record->sourceScopeLabel()),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }
}
