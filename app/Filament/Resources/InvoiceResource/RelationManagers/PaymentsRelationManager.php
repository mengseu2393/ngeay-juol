<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Enums\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Payments are created through the invoice relationship, so the Payment model's
 * saved-event recomputes amount_paid + payment_status automatically (ledger stays
 * consistent — the old direct-write drift can't happen here).
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('recorded_by_id')->default(fn () => auth()->id()),
            Forms\Components\TextInput::make('amount')->numeric()->prefix('$')->required(),
            Forms\Components\DateTimePicker::make('paid_at')->default(now())->required(),
            Forms\Components\Select::make('method')->options(PaymentMethod::class)->default(PaymentMethod::Cash)->required(),
            Forms\Components\TextInput::make('transaction_ref'),
            Forms\Components\TextInput::make('receipt_number'),
            Forms\Components\Textarea::make('note')->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('receipt_number')
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('USD'),
                Tables\Columns\TextColumn::make('method')->badge(),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
                Tables\Columns\TextColumn::make('receipt_number')->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }
}
