<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\InvoiceResource\Concerns\HasInvoiceDocumentActions;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    use HasInvoiceDocumentActions;
    use ScopesToActiveProperty;

    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Billing';
    }

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Invoice'))
                ->schema([
                    Forms\Components\Select::make('rental_id')
                        ->relationship(
                            'rental',
                            'id',
                            fn ($query) => ActiveProperty::id()
                                ? $query->where('property_id', ActiveProperty::id())
                                : $query,
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => "#{$record->id} · ".($record->tenant?->name ?? __('tenant')).' · '.($record->unit?->room_number ?? ''))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabledOn('edit'),
                    Forms\Components\Select::make('payment_status')
                        ->options(InvoiceStatus::class)
                        ->default(InvoiceStatus::Pending)
                        ->required(),
                    Forms\Components\Hidden::make('period_start')
                        ->default(fn () => now()->startOfMonth()),
                    Forms\Components\Hidden::make('period_end')
                        ->default(fn () => now()->endOfMonth()),
                    Forms\Components\DatePicker::make('issue_date')->default(now())->required(),
                    Forms\Components\Hidden::make('due_date')
                        ->default(fn () => now()->endOfMonth()->addDays(7)),
                    Forms\Components\TextInput::make('amount_due')
                        ->numeric()->prefix(fn () => Money::activeSymbol())
                        ->helperText(__('Computed from line items.'))
                        ->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('amount_paid')
                        ->numeric()->prefix(fn () => Money::activeSymbol())
                        ->helperText(__('Computed from the payments ledger.'))
                        ->disabled()->dehydrated(false),
                    Forms\Components\Textarea::make('notes')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('row_number')
                    ->label('#')
                    ->rowIndex(),
                Tables\Columns\TextColumn::make('invoice_number')
                    ->searchable()->sortable()
                    ->action(
                        Tables\Actions\Action::make('viewSlip')
                            ->label(__('View invoice'))
                            ->modalHeading('')
                            ->modalCloseButton(true)
                            ->modalWidth('4xl')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('Close'))
                            ->color('gray')
                            ->modalContent(function (Invoice $record) {
                                $record->loadMissing(['lines.utilityUsage.propertyUtility', 'rental.unit.property', 'tenant', 'property']);
                                return view('components.invoice-slip-modal', ['invoice' => $record]);
                            })
                    ),
                Tables\Columns\TextColumn::make('tenant.name')->label(__('Tenant'))->searchable(),
                Tables\Columns\TextColumn::make('amount_due')
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('balance')
                    ->state(fn (Invoice $r) => $r->balance)
                    ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('payment_status')->badge()
                    // Click the status to manage payments: add when owing, or edit
                    // existing payments once paid.
                    ->action(static::managePaymentsAction('managePaymentsFromStatus'))
                    ->tooltip(fn (Invoice $record) => $record->balance > 0
                        ? __('Click to record a payment')
                        : __('Click to view / edit payments')),
                Tables\Columns\TextColumn::make('due_date')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('due_date')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label(__('Period'))
                            ->options([
                                'this_month' => __('This month'),
                                'last_month' => __('Last month'),
                                'last_2_months' => __('Last 2 months'),
                                'last_3_months' => __('Last 3 months'),
                                'last_6_months' => __('Last 6 months'),
                                'this_year' => __('This year'),
                                'custom' => __('Custom'),
                            ])
                            ->placeholder(__('All time'))
                            ->live(),

                        Forms\Components\DatePicker::make('from')
                            ->label(__('From'))
                            ->visible(fn (Forms\Get $get) => $get('period') === 'custom'),

                        Forms\Components\DatePicker::make('until')
                            ->label(__('Until'))
                            ->visible(fn (Forms\Get $get) => $get('period') === 'custom'),
                    ])
                    ->query(function ($query, array $data) {
                        $period = $data['period'] ?? null;
                        if (! $period) {
                            return $query;
                        }

                        if ($period === 'custom') {
                            return $query
                                ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
                                ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d));
                        }

                        $now = now();
                        [$from, $until] = match ($period) {
                            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                            'last_month' => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
                            'last_2_months' => [$now->copy()->subMonths(2)->startOfMonth(), $now->copy()->endOfMonth()],
                            'last_3_months' => [$now->copy()->subMonths(3)->startOfMonth(), $now->copy()->endOfMonth()],
                            'last_6_months' => [$now->copy()->subMonths(6)->startOfMonth(), $now->copy()->endOfMonth()],
                            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                            default => [null, null],
                        };

                        return $query
                            ->when($from, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
                            ->when($until, fn ($q, $d) => $q->whereDate('due_date', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $period = $data['period'] ?? null;
                        if (! $period) {
                            return [];
                        }

                        $label = match ($period) {
                            'this_month' => __('This month'),
                            'last_month' => __('Last month'),
                            'last_2_months' => __('Last 2 months'),
                            'last_3_months' => __('Last 3 months'),
                            'last_6_months' => __('Last 6 months'),
                            'this_year' => __('This year'),
                            'custom' => collect([
                                ($data['from'] ?? null) ? __('From').' '.\Carbon\Carbon::parse($data['from'])->toFormattedDateString() : null,
                                ($data['until'] ?? null) ? __('Until').' '.\Carbon\Carbon::parse($data['until'])->toFormattedDateString() : null,
                            ])->filter()->implode(' — ') ?: __('Custom'),
                            default => null,
                        };

                        return $label ? [Tables\Filters\Indicator::make($label)->removeField('period')] : [];
                    }),
                Tables\Filters\SelectFilter::make('payment_status')->options(InvoiceStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::tableDocumentActions(),
                    static::managePaymentsAction('managePayments'),
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
     * "Manage payments" modal — opened from the row action or by clicking the
     * status badge. Lists the invoice's recorded payments so a paid invoice shows
     * its detail, and lets you add or delete them. Every add/edit/delete flows
     * through the Payment model, whose saved/deleted events recompute amount_paid
     * + payment_status, so the ledger never drifts. Takes a name so it can be
     * registered in two places without clashing.
     */
    protected static function managePaymentsAction(string $name): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)
            ->label(__('Payments'))
            ->icon('heroicon-o-banknotes')
            ->color(fn (Invoice $record) => $record->balance > 0 ? 'success' : 'gray')
            ->modalHeading(fn (Invoice $record) => __('Payments').' · '.$record->invoice_number)
            ->modalDescription(fn (Invoice $record) => __('Total').': '.Money::formatForRecord($record->amount_due, $record)
                .' · '.__('Paid').': '.Money::formatForRecord($record->amount_paid, $record)
                .' · '.__('Balance').': '.Money::formatForRecord($record->balance, $record))
            ->modalSubmitActionLabel(__('Save'))
            // Existing payments become repeater rows; seed one row when nothing is
            // paid yet so "record a payment" stays one click.
            ->fillForm(function (Invoice $record) {
                $rows = $record->payments()->orderBy('paid_at')->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (string) $p->amount,
                    'paid_at' => $p->paid_at,
                    'method' => $p->method,
                    'transaction_ref' => $p->transaction_ref,
                    'receipt_number' => $p->receipt_number,
                    'note' => $p->note,
                ])->all();

                if (empty($rows) && (float) $record->balance > 0) {
                    $rows[] = ['id' => null, 'amount' => (string) $record->balance, 'paid_at' => now(), 'method' => PaymentMethod::Cash->value];
                }

                return ['payments' => $rows];
            })
            ->form([
                Forms\Components\Repeater::make('payments')
                    ->hiddenLabel()
                    ->addActionLabel(__('Add payment'))
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state) => ($state['amount'] ? Money::activeFormat($state['amount']) : __('New payment'))
                        .($state['paid_at'] ?? null ? ' · '.\Illuminate\Support\Carbon::parse($state['paid_at'])->format('d M Y') : ''))
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\TextInput::make('amount')->numeric()->prefix(fn () => Money::activeSymbol())->required()->minValue(0.01),
                        Forms\Components\DateTimePicker::make('paid_at')->label(__('Paid at'))->default(now())->required(),
                        Forms\Components\Select::make('method')->label(__('Method'))->options(PaymentMethod::class)->default(PaymentMethod::Cash)->required(),
                        Forms\Components\TextInput::make('transaction_ref')->label(__('Transaction ref')),
                        Forms\Components\TextInput::make('receipt_number')->label(__('Receipt number')),
                        Forms\Components\Textarea::make('note')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ])
            ->action(function (Invoice $record, array $data) {
                static::reconcilePayments($record, $data['payments'] ?? []);

                $record->refresh();
                Notification::make()
                    ->title(__('Payments updated'))
                    ->body(__('Paid').': '.Money::formatForRecord($record->amount_paid, $record)
                        .' · '.__('Balance').': '.Money::formatForRecord($record->balance, $record)
                        .' · '.$record->payment_status->getLabel())
                    ->success()->send();
            });
    }

    /**
     * Reconcile the submitted payment rows against what's stored: delete removed
     * rows, update changed ones, create new ones — each through the model so the
     * ledger recomputes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected static function reconcilePayments(Invoice $invoice, array $rows): void
    {
        $keptIds = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        // Deletions first (rows removed in the modal).
        $invoice->payments()->whereNotIn('id', $keptIds ?: [0])->each(fn ($p) => $p->delete());

        foreach ($rows as $row) {
            $attributes = [
                'amount' => $row['amount'],
                'paid_at' => $row['paid_at'] ?? now(),
                'method' => $row['method'] ?? PaymentMethod::Cash,
                'transaction_ref' => $row['transaction_ref'] ?? null,
                'receipt_number' => $row['receipt_number'] ?? null,
                'note' => $row['note'] ?? null,
            ];

            if (! empty($row['id'])) {
                $invoice->payments()->whereKey($row['id'])->first()?->update($attributes);
            } else {
                $invoice->recordPayment(['recorded_by_id' => auth()->id()] + $attributes);
            }
        }
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InvoiceLinesRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
