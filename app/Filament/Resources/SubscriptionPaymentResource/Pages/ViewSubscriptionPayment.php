<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscriptionPayment extends ViewRecord
{
    protected static string $resource = SubscriptionPaymentResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Payment'))
                ->schema([
                    Infolists\Components\TextEntry::make('subscription.landlord.name')->label('Landlord'),
                    Infolists\Components\TextEntry::make('subscription.plan.name')->label('Plan'),
                    Infolists\Components\TextEntry::make('amount')
                        ->money(fn ($record) => $record->currency),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('method')->label('Method'),
                    Infolists\Components\TextEntry::make('paid_at')->dateTime(),
                ])->columns(3),

            Infolists\Components\Section::make(__('Period'))
                ->schema([
                    Infolists\Components\TextEntry::make('covers_from')->date()->label('From'),
                    Infolists\Components\TextEntry::make('covers_to')->date()->label('To'),
                    Infolists\Components\TextEntry::make('receipt_number')->label('Receipt'),
                    Infolists\Components\TextEntry::make('gateway')->placeholder('—'),
                    Infolists\Components\TextEntry::make('gateway_transaction_id')->placeholder('—'),
                ])->columns(3),

            Infolists\Components\Section::make(__('Notes'))
                ->schema([
                    Infolists\Components\TextEntry::make('note')->placeholder('—'),
                    Infolists\Components\TextEntry::make('recordedBy.name')->label('Recorded by'),
                ])->columns(2),
        ]);
    }
}
