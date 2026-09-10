<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Tables\RowActionGroup;
use App\Models\Payment;
use App\Support\InvoicePaper;
use App\Support\Money;
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
            Forms\Components\TextInput::make('amount')->numeric()->required(),
            Forms\Components\Select::make('currency')
                ->label(__('Payment currency'))
                ->options([
                    'USD' => 'USD',
                    'KHR' => 'KHR',
                ])
                ->default(fn ($livewire) => Money::forRecord($livewire->getOwnerRecord()))
                ->required(),
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
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state, Payment $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('method')->badge(),
                Tables\Columns\TextColumn::make('recordedBy.name')->label(__('Recorded by')),
                Tables\Columns\TextColumn::make('receipt_number')->placeholder('—'),
            ])
            ->headerActions([
                // The same compact modal as the invoice row, resolved against the
                // owner record, so both surfaces record a payment identically
                // (through Invoice::recordPayment) and hide on a settled invoice.
                InvoiceResource::recordPaymentAction(
                    'recordPaymentFromRelation',
                    fn ($record, $livewire) => $livewire->getOwnerRecord(),
                ),
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                RowActionGroup::make([
                    static::receiptActions(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * "Print receipt" — the slip handed to a tenant who just paid cash.
     *
     * Two sizes only: the 58 mm thermal roll (what a landlord actually prints)
     * and A5 for a filed paper copy. The route streams inline so the browser's
     * print dialog is one click away.
     */
    protected static function receiptActions(): Tables\Actions\ActionGroup
    {
        return Tables\Actions\ActionGroup::make([
            Tables\Actions\Action::make('receipt_58mm')
                ->label(__('Receipt').' · '.InvoicePaper::label('58mm'))
                ->icon('heroicon-o-printer')
                ->url(fn (Payment $record) => route('payments.receipt', [
                    'payment' => $record,
                    'size' => '58mm',
                    'mode' => 'stream',
                ]))
                ->openUrlInNewTab(),
            Tables\Actions\Action::make('receipt_a5')
                ->label(__('Receipt').' · '.InvoicePaper::label('a5'))
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (Payment $record) => route('payments.receipt', [
                    'payment' => $record,
                    'size' => 'a5',
                    'mode' => 'stream',
                ]))
                ->openUrlInNewTab(),
        ])
            ->label(__('Print receipt'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            // Nested inside the row's "..." group, an ActionGroup keeps its
            // icon-only trigger unless told otherwise (see HasInvoiceDocumentActions).
            ->grouped();
    }
}
