<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Issue invoice')),
        ];
    }

    /**
     * ListRecords chains a default record URL (edit) and record action (view) on
     * AFTER the resource's own table config. In the card layout the merged ledger
     * uses, that wraps the whole row in one <a>/<button>, which swallows the
     * per-column actions — clicking the status badge navigated to Edit instead of
     * opening the Payments modal. Drop both: the invoice number opens the slip,
     * the status badge opens Payments, and the ⋮ menu still has View / Edit.
     */
    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordUrl(null)
            ->recordAction(null);
    }
}
