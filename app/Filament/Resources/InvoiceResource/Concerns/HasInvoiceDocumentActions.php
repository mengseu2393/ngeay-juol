<?php

namespace App\Filament\Resources\InvoiceResource\Concerns;

use App\Models\Invoice;
use App\Support\InvoicePaper;

/**
 * Shared "Print / Export" menu for the invoice list row and the edit page header.
 * One PDF action per paper size (A4, A5, 80/65 mm receipt) plus an Excel export,
 * each opening its document route in a new tab. The two helpers differ only in
 * namespace: tables use Filament\Tables\Actions, pages use Filament\Actions.
 */
trait HasInvoiceDocumentActions
{
    /**
     * The grouped document actions for the invoice table row.
     */
    public static function tableDocumentActions(): \Filament\Tables\Actions\ActionGroup
    {
        $children = [];

        // Open the A4 PDF inline (print preview) for an immediate browser print.
        $children[] = \Filament\Tables\Actions\Action::make('print')
            ->label(__('Print'))
            ->icon('heroicon-o-printer')
            ->url(fn (Invoice $record) => route('invoices.pdf', ['invoice' => $record, 'size' => 'a4', 'mode' => 'stream']))
            ->openUrlInNewTab();

        foreach (InvoicePaper::options() as $size => $label) {
            $children[] = \Filament\Tables\Actions\Action::make('pdf_'.$size)
                ->label('PDF · '.$label)
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (Invoice $record) => route('invoices.pdf', ['invoice' => $record, 'size' => $size]))
                ->openUrlInNewTab();
        }

        $children[] = \Filament\Tables\Actions\Action::make('excel')
            ->label(__('Excel'))
            ->icon('heroicon-o-table-cells')
            ->url(fn (Invoice $record) => route('invoices.excel', ['invoice' => $record]))
            ->openUrlInNewTab();

        return \Filament\Tables\Actions\ActionGroup::make($children)
            ->label(__('Print / Export'))
            ->icon('heroicon-o-printer')
            ->color('gray');
    }

    /**
     * The same menu for a record page header (Filament\Actions namespace). The
     * mounted record is passed to the url closures.
     */
    public static function pageDocumentActions(): \Filament\Actions\ActionGroup
    {
        $children = [];

        // Open the A4 PDF inline (print preview) for an immediate browser print.
        $children[] = \Filament\Actions\Action::make('print')
            ->label(__('Print'))
            ->icon('heroicon-o-printer')
            ->url(fn (Invoice $record) => route('invoices.pdf', ['invoice' => $record, 'size' => 'a4', 'mode' => 'stream']))
            ->openUrlInNewTab();

        foreach (InvoicePaper::options() as $size => $label) {
            $children[] = \Filament\Actions\Action::make('pdf_'.$size)
                ->label('PDF · '.$label)
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (Invoice $record) => route('invoices.pdf', ['invoice' => $record, 'size' => $size]))
                ->openUrlInNewTab();
        }

        $children[] = \Filament\Actions\Action::make('excel')
            ->label(__('Excel'))
            ->icon('heroicon-o-table-cells')
            ->url(fn (Invoice $record) => route('invoices.excel', ['invoice' => $record]))
            ->openUrlInNewTab();

        return \Filament\Actions\ActionGroup::make($children)
            ->label(__('Print / Export'))
            ->icon('heroicon-o-printer')
            ->color('gray');
    }
}
