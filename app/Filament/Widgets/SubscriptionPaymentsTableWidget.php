<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SubscriptionPaymentResource;
use App\Models\SubscriptionPayment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments half of the merged subscriptions page (SubscriptionResource list page,
 * `?tab=payments`). Columns and filters are reused from SubscriptionPaymentResource
 * so both stay in sync; row actions are re-declared as plain link actions because a
 * widget has no resource context to build view/edit URLs from.
 */
class SubscriptionPaymentsTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return SubscriptionPaymentResource::canAccess();
    }

    public function table(Table $table): Table
    {
        return SubscriptionPaymentResource::table($table)
            ->query(fn (): Builder => SubscriptionPaymentResource::getEloquentQuery()
                ->with(['subscription.landlord', 'subscription.plan', 'recordedBy']))
            ->defaultPaginationPageOption(10)
            ->recordUrl(fn (SubscriptionPayment $record): string => SubscriptionPaymentResource::getUrl('view', ['record' => $record]))
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view')
                        ->label(__('View'))
                        ->icon('heroicon-m-eye')
                        ->color('gray')
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionPaymentResource::getUrl('view', ['record' => $record])),
                    Tables\Actions\Action::make('edit')
                        ->label(__('Edit'))
                        ->icon('heroicon-m-pencil-square')
                        ->color('gray')
                        ->url(fn (SubscriptionPayment $record): string => SubscriptionPaymentResource::getUrl('edit', ['record' => $record])),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ]);
    }

    /** The tab bar already labels this table, so drop TableWidget's generated heading. */
    protected function makeTable(): Table
    {
        return $this->makeBaseTable();
    }

    /** Keep the numbered paginator the standalone list page used. */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        return $query->paginate(
            perPage: $this->getTableRecordsPerPage() === 'all' ? $query->count() : $this->getTableRecordsPerPage(),
            pageName: $this->getTablePaginationPageName(),
        );
    }
}
