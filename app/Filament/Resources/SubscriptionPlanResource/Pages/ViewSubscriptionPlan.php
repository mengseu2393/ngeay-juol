<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Resources\SubscriptionPlanResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscriptionPlan extends ViewRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Plan details'))
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('slug')->badge()->color('gray'),
                    Infolists\Components\TextEntry::make('description')->placeholder('—'),
                    Infolists\Components\TextEntry::make('billing_model')->badge(),
                    Infolists\Components\TextEntry::make('interval')->badge(),
                    Infolists\Components\TextEntry::make('price')
                        ->money(fn ($record) => $record->currency),
                    Infolists\Components\TextEntry::make('unit_price')
                        ->money(fn ($record) => $record->currency)
                        ->visible(fn ($record) => $record->unit_price !== null),
                ])->columns(3),

            Infolists\Components\Section::make(__('Limits'))
                ->schema([
                    Infolists\Components\TextEntry::make('max_units')
                        ->placeholder('Unlimited'),
                    Infolists\Components\TextEntry::make('max_properties')
                        ->placeholder('Unlimited'),
                    Infolists\Components\TextEntry::make('trial_days')
                        ->suffix(' days'),
                    Infolists\Components\TextEntry::make('grace_days')
                        ->suffix(' days'),
                ])->columns(4),

            Infolists\Components\Section::make(__('Features'))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('features'),
                ]),

            Infolists\Components\Section::make(__('Visibility'))
                ->schema([
                    Infolists\Components\IconEntry::make('is_active')->boolean(),
                    Infolists\Components\TextEntry::make('sort_order'),
                ])->columns(2),
        ]);
    }
}
