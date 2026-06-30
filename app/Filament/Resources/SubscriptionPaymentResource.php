<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionPaymentStatus;
use App\Filament\Resources\SubscriptionPaymentResource\Pages;
use App\Models\SubscriptionPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Payment details'))
                ->schema([
                    Forms\Components\Select::make('landlord_id')
                        ->label('Landlord')
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
                    Forms\Components\Hidden::make('status')
                        ->default(SubscriptionPaymentStatus::Succeeded),
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
                    ->label('Landlord')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label('Receipt')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('method')->label('Method'),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('gateway')->badge()->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SubscriptionPaymentStatus::class),
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
