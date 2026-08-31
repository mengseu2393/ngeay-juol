<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OverdueInvoicesWidget extends BaseWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Overdue Invoices');
    }

    public function table(Table $table): Table
    {
        $query = $this->scopeToActiveProperty(Invoice::query())
            ->with(['rental.unit'])
            ->whereIn('payment_status', [
                InvoiceStatus::Overdue,
                InvoiceStatus::Pending,
                InvoiceStatus::Partial,
            ])
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date', 'asc')
            ->limit(5);

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label(__('Invoice'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.unit.room_number')
                    ->label(__('Room'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('amount_due')
                    ->label(__('Amount Due'))
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatInvoiceAmount($record, 'due'))
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('Due Date'))
                    ->date('d M Y')
                    ->color('danger')
                    ->weight('bold'),
            ])
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->url(fn (Invoice $record): string => route('filament.landlord.resources.invoices.edit', $record)),
            ]);
    }
}
