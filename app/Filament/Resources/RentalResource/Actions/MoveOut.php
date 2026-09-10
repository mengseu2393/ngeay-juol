<?php

namespace App\Filament\Resources\RentalResource\Actions;

use App\Enums\BillingType;
use App\Enums\DepositDeductionCategory;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Policies\RentalPolicy;
use App\Services\MeterReadingResolver;
use App\Services\MoveOutService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The "Move out" action, in both flavours Filament needs: a table row action and
 * a view-page header action. Both build the same schema and both funnel into
 * {@see MoveOutService}, so the workflow cannot drift between the two surfaces.
 *
 * Gated on the existing `update` ability of {@see RentalPolicy} —
 * moving a tenant out is an edit of that tenancy, not a separate permission.
 */
class MoveOut
{
    public static function table(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('move_out')
            ->label(__('Move out'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->modalWidth('3xl')
            ->modalHeading(fn (Rental $record) => __('Move out').' — '.static::occupantLabel($record))
            ->modalSubmitActionLabel(__('Complete move-out'))
            ->visible(fn (Rental $record) => static::isAvailableFor($record))
            ->form(fn (Rental $record) => static::schema($record))
            ->action(fn (Rental $record, array $data) => static::handle($record, $data));
    }

    public static function page(): Action
    {
        return Action::make('move_out')
            ->label(__('Move out'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->modalWidth('3xl')
            ->modalHeading(fn (Rental $record) => __('Move out').' — '.static::occupantLabel($record))
            ->modalSubmitActionLabel(__('Complete move-out'))
            ->visible(fn (Rental $record) => static::isAvailableFor($record))
            ->form(fn (Rental $record) => static::schema($record))
            ->action(fn (Rental $record, array $data) => static::handle($record, $data));
    }

    protected static function isAvailableFor(Rental $record): bool
    {
        return ! $record->hasMovedOut() && (bool) auth()->user()?->can('update', $record);
    }

    protected static function occupantLabel(Rental $record): string
    {
        return $record->occupant_name ?: (__('Room').' '.($record->unit?->room_number ?? '—'));
    }

    /** @return array<int, Forms\Components\Component> */
    protected static function schema(Rental $record): array
    {
        $depositCurrency = Money::normalize($record->security_deposit_currency ?: $record->monthly_rent_currency);
        $deposit = (float) ($record->security_deposit ?: 0);

        return [
            Forms\Components\Section::make(__('Move-out'))
                ->schema([
                    Forms\Components\DatePicker::make('move_out_date')
                        ->label(__('Move-out date'))
                        ->default(now())
                        ->minDate($record->start_date)
                        ->required(),
                    Forms\Components\Textarea::make('move_out_reason')
                        ->label(__('Reason for leaving'))
                        ->rows(2),
                ])->columns(2),

            Forms\Components\Section::make(__('Final meter readings'))
                ->description(__('Closing index for each metered utility. Leave blank to skip a utility.'))
                ->schema(static::readingFields($record))
                ->columns(2)
                ->visible(fn () => static::readingFields($record) !== []),

            Forms\Components\Section::make(__('Final invoice'))
                ->schema([
                    Forms\Components\Toggle::make('create_final_invoice')
                        ->label(__('Create a final invoice'))
                        ->default(true)
                        ->live(),
                    Forms\Components\Toggle::make('prorate_final_rent')
                        ->label(__('Prorate the final month by day'))
                        ->helperText(__('Off charges a full month for the closing period.'))
                        ->default(true)
                        ->visible(fn (Forms\Get $get) => (bool) $get('create_final_invoice')),
                ])->columns(2),

            Forms\Components\Section::make(__('Security deposit settlement'))
                ->description(__('Deposit held').': '.Money::format($deposit, $depositCurrency))
                ->schema([
                    Forms\Components\Repeater::make('deductions')
                        ->label(__('Deductions'))
                        ->helperText(__('Itemise anything withheld from the deposit. The refund is the deposit less these amounts.'))
                        ->schema([
                            Forms\Components\Select::make('category')
                                ->label(__('Category'))
                                ->options(DepositDeductionCategory::class)
                                ->default(DepositDeductionCategory::Other)
                                ->required(),
                            Forms\Components\TextInput::make('reason')
                                ->label(__('Reason'))
                                ->required(),
                            Forms\Components\TextInput::make('amount')
                                ->label(__('Amount'))
                                ->numeric()
                                ->minValue(0.01)
                                ->required(),
                            Forms\Components\Select::make('currency')
                                ->label(__('Currency'))
                                ->options([
                                    'USD' => 'USD ($)',
                                    'KHR' => 'KHR (៛)',
                                ])
                                ->default($depositCurrency)
                                ->required(),
                        ])
                        ->columns(4)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add a deduction'))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('refund_reference')
                        ->label(__('Refund reference'))
                        ->placeholder(__('Transfer / receipt number')),
                    Forms\Components\DatePicker::make('refund_paid_at')
                        ->label(__('Refund paid on'))
                        ->helperText(__('Leave blank until the refund has actually been paid out.')),
                    Forms\Components\Textarea::make('notes')
                        ->label(__('Settlement notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),
        ];
    }

    /**
     * One numeric input per metered utility on this tenancy's property, showing
     * the index the next reading will be measured from.
     *
     * @return array<int, Forms\Components\Component>
     */
    protected static function readingFields(Rental $record): array
    {
        if (! $record->unit_id || ! $record->property_id) {
            return [];
        }

        $resolver = app(MeterReadingResolver::class);

        return PropertyUtility::withoutGlobalScopes()
            ->where('property_id', $record->property_id)
            ->where('is_active', true)
            ->where('billing_type', '!=', BillingType::Flat->value)
            ->orderBy('name')
            ->get()
            ->map(function (PropertyUtility $utility) use ($record, $resolver) {
                $previous = $resolver->previousReading((int) $record->unit_id, (int) $utility->getKey());

                return Forms\Components\TextInput::make('final_readings.'.$utility->getKey())
                    ->label($utility->name.($utility->unit_of_measure ? ' ('.$utility->unit_of_measure.')' : ''))
                    ->helperText(__('Previous reading').': '.rtrim(rtrim(number_format($previous, 3, '.', ''), '0'), '.'))
                    ->numeric()
                    ->minValue(0);
            })
            ->all();
    }

    protected static function handle(Rental $record, array $data): void
    {
        $data['deductions'] = array_values($data['deductions'] ?? []);

        try {
            $result = app(MoveOutService::class)->execute($record, $data, auth()->id());
        } catch (ValidationException $e) {
            Notification::make()
                ->title(__('Move-out could not be completed'))
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()->send();

            return;
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()
                ->title(__('Move-out could not be completed'))
                ->body(__($e->getMessage()))
                ->danger()->send();

            return;
        }

        $settlement = $result['settlement'];
        $currency = Money::normalize($settlement->currency);

        Notification::make()
            ->title(__('Move-out complete'))
            ->body(
                __('Refund due').': '.Money::format($settlement->refund_amount, $currency)
                .' · '.__('Deductions').': '.Money::format($settlement->deductions_total, $currency)
                .($result['invoice'] ? ' · '.__('Final invoice').': '.$result['invoice']->invoice_number : '')
            )
            ->success()->persistent()->send();
    }
}
