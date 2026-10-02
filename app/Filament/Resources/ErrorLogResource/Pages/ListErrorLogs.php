<?php

namespace App\Filament\Resources\ErrorLogResource\Pages;

use App\Filament\Resources\ErrorLogResource;
use Filament\Resources\Pages\ListRecords;

class ListErrorLogs extends ListRecords
{
    protected static string $resource = ErrorLogResource::class;

    /** Entries are written by the exception handler only: no create button. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
