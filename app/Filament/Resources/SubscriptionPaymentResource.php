<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPaymentStatus;
use App\Filament\Resources\SubscriptionPaymentResource\Pages;
use App\Models\SubscriptionPayment;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPaymentResource extends Resource
{
    protected static ?string $model = SubscriptionPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'subscription-payments';

    public static function getNavigationLabel(): string
    {
        return __('Subscription payments');
    }

    public static function getModelLabel(): string
    {
        return __('Subscription payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Subscription payments');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Merged into SubscriptionResource's list page as its "Payments" tab, so this
     * resource no longer gets its own navigation entry. Its create/view/edit routes
     * stay registered — the merged page links to them.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Payment details'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->label(__('Landlord'))
                        ->options(fn () => \App\Models\User::role('landlord')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $sub = \App\Models\Subscription::where('landlord_id', $state)->first();
                            if ($sub) {
                                $set('subscription_id', $sub->id);
                                $set('amount', $sub->price); // auto-fill amount
                            } else {
                                $set('subscription_id', null);
                                $set('amount', null);
                            }
                        }),
                    Forms\Components\Hidden::make('subscription_id')->required(),
                    Forms\Components\TextInput::make('amount')
                        ->required()->numeric()->prefix('$'),
                    Forms\Components\TextInput::make('currency')
                        ->required()->maxLength(3)->default('USD'),
                    Forms\Components\Select::make('method')
                        ->options(\App\Enums\PaymentMethod::class)
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->options(SubscriptionPaymentStatus::class)
                        ->default(SubscriptionPaymentStatus::Succeeded->value)
                        ->required(),
                    Forms\Components\DatePicker::make('paid_at'),
                    Forms\Components\DatePicker::make('covers_from')->required(),
                    Forms\Components\DatePicker::make('covers_to')->required(),
                    Forms\Components\TextInput::make('receipt_number'),
                    Forms\Components\Textarea::make('note')->rows(2),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscription.landlord.name')
                    ->label(__('Landlord'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscription.plan.name')
                    ->label(__('Plan'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label(__('Receipt'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('method')
                    ->label(__('Payment method'))
                    ->formatStateUsing(fn (PaymentMethod|string|int|null $state): string => match (true) {
                        $state instanceof PaymentMethod => $state->getLabel(),
                        is_numeric($state) => PaymentMethod::tryFrom((int) $state)?->getLabel() ?? (string) $state,
                        default => __((string) $state),
                    }),
                Tables\Columns\TextColumn::make('covers_from')
                    ->label(__('Coverage from'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('covers_to')
                    ->label(__('Coverage until'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->label(__('Payment date'))
                    ->date('d-m-Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('gateway')
                    ->label(__('Gateway'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        null, '' => '—',
                        'manual' => __('Manual'),
                        default => __(ucfirst(str_replace(['_', '-'], ' ', $state))),
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label(__('Recorded by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('paid_at')
                    ->label(__('Payment date'))
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
                    ->query(function (Builder $query, array $data): Builder {
                        $period = $data['period'] ?? null;

                        if (! $period) {
                            return $query;
                        }

                        if ($period === 'custom') {
                            return $query
                                ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                                ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date));
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
                            ->when($from, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                            ->when($until, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date));
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
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(SubscriptionPaymentStatus::class),
                Tables\Filters\SelectFilter::make('landlord_id')
                    ->label(__('Landlord'))
                    ->relationship('landlord', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('method')
                    ->label(__('Method'))
                    ->options(\App\Enums\PaymentMethod::class),
                Tables\Filters\Filter::make('coverage_period')
                    ->label(__('Coverage period'))
                    ->form([
                        Forms\Components\DatePicker::make('coverage_from')
                            ->label(__('Coverage from')),
                        Forms\Components\DatePicker::make('coverage_until')
                            ->label(__('Coverage until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $coverageFrom = $data['coverage_from'] ?? null;
                        $coverageUntil = $data['coverage_until'] ?? null;

                        if (! $coverageFrom && ! $coverageUntil) {
                            return $query;
                        }

                        return $query
                            ->when($coverageFrom, fn (Builder $q, $date) => $q->whereDate('covers_to', '>=', $date))
                            ->when($coverageUntil, fn (Builder $q, $date) => $q->whereDate('covers_from', '<=', $date));
                    }),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPayments::route('/'),
            'create' => Pages\CreateSubscriptionPayment::route('/create'),
            'view' => Pages\ViewSubscriptionPayment::route('/{record}'),
            'edit' => Pages\EditSubscriptionPayment::route('/{record}/edit'),
        ];
    }
}
