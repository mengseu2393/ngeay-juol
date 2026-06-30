<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 5;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Billing';
    }

    public static function getModelLabel(): string
    {
        return __('Payment');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('recorded_by_id')->default(fn () => auth()->id()),
            Forms\Components\Select::make('invoice_id')
                ->relationship(
                    'invoice',
                    'invoice_number',
                    fn ($query) => ActiveProperty::id()
                        ? $query->where('property_id', ActiveProperty::id())
                        : $query,
                )
                ->searchable()->preload()->required()
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('amount')->numeric()->prefix('$')->required(),
            Forms\Components\DateTimePicker::make('paid_at')->default(now())->required(),
            Forms\Components\Select::make('method')->options(PaymentMethod::class)->default(PaymentMethod::Cash)->required(),
            Forms\Components\TextInput::make('transaction_ref'),
            Forms\Components\TextInput::make('receipt_number'),
            Forms\Components\Textarea::make('note')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice.invoice_number')->label('Invoice')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('method')->badge(),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by')->toggleable(),
                Tables\Columns\TextColumn::make('receipt_number')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')->options(PaymentMethod::class),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Scope payments to invoices the actor can see (Invoice's LandlordScope flows
     * through), and — when a property is active — to that property via the invoice.
     * Payments carry no property_id, so we reach it through the relationship.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereHas('invoice');

        if (($propertyId = ActiveProperty::id()) !== null) {
            $query->whereHas('invoice', fn (Builder $q) => $q->where('property_id', $propertyId));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
