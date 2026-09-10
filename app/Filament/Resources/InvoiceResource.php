<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\InvoiceResource\Concerns\HasInvoiceDocumentActions;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers;
use App\Filament\Tables\RowActionGroup;
use App\Models\Invoice;
use App\Support\ActiveProperty;
use App\Support\Money;
use Carbon\Carbon;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Payments no longer have their own page — they live in the expandable panel
     * on each invoice row — so the sidebar entry names both.
     */
    public static function getNavigationLabel(): string
    {
        return __('Invoices & Payments');
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
            // The payments panel renders for every row, so pull the ledger (and the
            // tenant) in one go instead of N queries per page.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['payments.recordedBy', 'tenant']))
            ->columns([
                // One combined ledger: the invoice summary on top, its payments in
                // the collapsible panel underneath — this replaces the standalone
                // /app/payments page. A collapsible Panel switches Filament to its
                // card layout (no column headers), so the row is built from
                // Split/Stack and every value carries its own label prefix.
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('invoice_number')
                            ->weight(FontWeight::Bold)
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
                        Tables\Columns\TextColumn::make('tenant.name')
                            ->label(__('Tenant'))
                            ->icon('heroicon-m-user')
                            ->size(TextColumnSize::Small)
                            ->color('gray')
                            ->searchable(),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('amount_due')
                            ->prefix(fn () => __('Total').': ')
                            ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                            ->size(TextColumnSize::Small)
                            ->sortable(),
                        Tables\Columns\TextColumn::make('balance')
                            ->state(fn (Invoice $r) => $r->balance)
                            ->prefix(fn () => __('Balance').': ')
                            ->formatStateUsing(fn ($state, Invoice $record) => Money::formatForRecord($state, $record))
                            ->size(TextColumnSize::Small)
                            ->weight(FontWeight::Medium)
                            ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success'),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('payment_status')->badge()
                            // Click the status to manage payments: add when owing, or
                            // edit existing payments once paid.
                            ->action(static::managePaymentsAction('managePaymentsFromStatus'))
                            ->tooltip(fn (Invoice $record) => $record->balance > 0
                                ? __('Click to record a payment')
                                : __('Click to view / edit payments')),
                        Tables\Columns\TextColumn::make('due_date')
                            ->prefix(fn () => __('Due').' ')
                            ->date()
                            ->size(TextColumnSize::Small)
                            ->color('gray')
                            ->sortable(),
                    ])->space(1)->alignment(Alignment::End),
                ])->from('md'),

                // Expandable payments ledger (chevron on the right of each row).
                Tables\Columns\Layout\Panel::make([
                    Tables\Columns\ViewColumn::make('payments_ledger')
                        ->label(__('Payments'))
                        ->view('filament.tables.invoice-payments-panel'),
                ])->collapsible(),
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
                                ($data['from'] ?? null) ? __('From').' '.Carbon::parse($data['from'])->toFormattedDateString() : null,
                                ($data['until'] ?? null) ? __('Until').' '.Carbon::parse($data['until'])->toFormattedDateString() : null,
                            ])->filter()->implode(' — ') ?: __('Custom'),
                            default => null,
                        };

                        return $label ? [Tables\Filters\Indicator::make($label)->removeField('period')] : [];
                    }),
                Tables\Filters\SelectFilter::make('payment_status')->options(InvoiceStatus::class),
                Tables\Filters\TrashedFilter::make(),
            ])
            // "Print all": one PDF containing every invoice matching the CURRENT
            // filters (capped at 200). The href is rebuilt each Livewire render, so
            // it always reflects the active filter state. Desktop opens the inline
            // PDF; on mobile/PWA rwPrintInvoiceLink() reroutes through the native
            // share sheet (no inline PDF viewer on phones).
            ->headerActions([
                Tables\Actions\Action::make('printAll')
                    ->label(__('Print all'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->badge(fn ($livewire) => $livewire->getFilteredTableQuery()->count() ?: null)
                    ->url(function ($livewire): ?string {
                        $ids = $livewire->getFilteredTableQuery()->limit(200)->pluck('id');

                        return $ids->isEmpty()
                            ? null
                            : route('invoices.batch-pdf', ['ids' => $ids->implode(','), 'mode' => 'stream']);
                    }, shouldOpenInNewTab: true)
                    ->extraAttributes(function ($livewire): array {
                        $ids = $livewire->getFilteredTableQuery()->limit(200)->pluck('id');

                        if ($ids->isEmpty()) {
                            return [];
                        }

                        return [
                            'data-download-url' => route('invoices.batch-pdf', ['ids' => $ids->implode(',')]),
                            'data-filename' => 'invoices-'.now()->format('Ymd').'.pdf',
                            'onclick' => 'return rwPrintInvoiceLink(event, this)',
                        ];
                    }),
            ])
            ->actions([
                RowActionGroup::make([
                    // First in the menu on purpose: "the tenant just paid me" is the
                    // most frequent thing a landlord does with an invoice row.
                    static::recordPaymentAction('recordPayment'),
                    static::tableDocumentActions(),
                    static::managePaymentsAction('managePayments'),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * "Record payment" — the one-click version of the daily job.
     *
     * Logging "the tenant paid $50" used to mean list → invoice → Payments tab →
     * Create → a full form; this is a compact modal on the row itself, pre-filled
     * with the outstanding balance so the common "paid it all in cash" case is
     * amount-untouched + Enter.
     *
     * The write goes through {@see Invoice::recordPayment()} and nothing else:
     * amount_paid and payment_status are recomputed from the ledger by the
     * Payment model's own saved hook, so this method must never touch either
     * (CLAUDE.md, "Data model").
     *
     * $invoiceUsing resolves the invoice from the action's context, because the
     * same action is registered on the invoice table (where it is the row record)
     * and on the payments relation-manager header (where it is the owner record).
     * It receives ($record, $livewire).
     */
    public static function recordPaymentAction(string $name = 'recordPayment', ?Closure $invoiceUsing = null): Tables\Actions\Action
    {
        $invoiceUsing ??= fn ($record, $livewire) => $record;

        return Tables\Actions\Action::make($name)
            ->label(__('Record payment'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Record payment'))
            // A settled invoice has nothing to collect: Paid and Cancelled both
            // hide the action rather than accepting a payment that would either
            // overpay or resurrect a cancelled bill.
            ->visible(function ($record, $livewire) use ($invoiceUsing): bool {
                $invoice = $invoiceUsing($record, $livewire);

                return $invoice instanceof Invoice && ! ($invoice->payment_status?->isSettled() ?? false);
            })
            ->modalHeading(fn ($record, $livewire) => __('Record payment').' · '.$invoiceUsing($record, $livewire)?->invoice_number)
            ->modalDescription(function ($record, $livewire) use ($invoiceUsing): string {
                $invoice = $invoiceUsing($record, $livewire);

                return __('Total').': '.Money::formatForRecord($invoice->amount_due, $invoice)
                    .' · '.__('Paid').': '.Money::formatForRecord($invoice->amount_paid, $invoice)
                    .' · '.__('Balance').': '.Money::formatForRecord($invoice->balance, $invoice);
            })
            // Defaults are seeded here rather than on each field's ->default():
            // the action's record is reliably injectable at this level, a field
            // closure's is not.
            ->fillForm(function ($record, $livewire) use ($invoiceUsing): array {
                $invoice = $invoiceUsing($record, $livewire);
                $currency = Money::forRecord($invoice);

                return [
                    'amount' => number_format(max(0.0, (float) $invoice->balance), Money::decimals($currency), '.', ''),
                    'currency' => $currency,
                    'method' => PaymentMethod::Cash->value,
                    'paid_at' => now(),
                ];
            })
            // An action's modal form has no ->columns(); the pairs are laid out
            // with an explicit Grid instead.
            ->form([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('amount')
                        ->label(__('Amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->prefix(fn (Forms\Get $get) => Money::symbol($get('currency'))),
                    Forms\Components\Select::make('currency')
                        ->label(__('Payment currency'))
                        ->options([
                            'USD' => 'USD',
                            'KHR' => 'KHR',
                        ])
                        // Live so the amount prefix follows the currency; the amount
                        // itself is deliberately left alone (it is the landlord's
                        // number once they've touched it).
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('method')
                        ->label(__('Method'))
                        ->options(PaymentMethod::class)
                        ->required(),
                    Forms\Components\DateTimePicker::make('paid_at')
                        ->label(__('Paid at'))
                        ->required(),
                    Forms\Components\TextInput::make('transaction_ref')->label(__('Transaction ref')),
                    Forms\Components\TextInput::make('receipt_number')
                        ->label(__('Receipt number'))
                        ->helperText(__('Leave blank to number the receipt automatically.')),
                    Forms\Components\Textarea::make('note')->label(__('Note'))->columnSpanFull(),
                ]),
            ])
            ->action(function ($record, $livewire, array $data) use ($invoiceUsing): void {
                $invoice = $invoiceUsing($record, $livewire);

                $payment = $invoice->recordPayment([
                    'recorded_by_id' => auth()->id(),
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? null,
                    'paid_at' => $data['paid_at'] ?? now(),
                    'method' => $data['method'] ?? PaymentMethod::Cash,
                    'transaction_ref' => $data['transaction_ref'] ?? null,
                    'receipt_number' => $data['receipt_number'] ?? null,
                    'note' => $data['note'] ?? null,
                ]);

                // The ledger hook wrote amount_paid/payment_status behind our back
                // (saveQuietly on another instance), so re-read before reporting.
                $invoice->refresh();

                Notification::make()
                    ->title(__('Payment recorded'))
                    ->body(__('Balance').': '.Money::formatForRecord($invoice->balance, $invoice)
                        .' · '.$invoice->payment_status->getLabel())
                    ->success()
                    ->actions([
                        NotificationAction::make('printReceipt')
                            ->label(__('Print receipt'))
                            ->icon('heroicon-o-printer')
                            ->url(
                                route('payments.receipt', ['payment' => $payment, 'size' => '58mm', 'mode' => 'stream']),
                                shouldOpenInNewTab: true,
                            ),
                    ])
                    ->send();
            });
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
