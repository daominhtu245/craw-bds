<?php

namespace App\Filament\Resources\RawItems\Pages;

use App\Filament\Resources\RawItems\RawItemResource;
use Filament\Resources\Pages\ListRecords;

class ListRawItems extends ListRecords
{
    protected static string $resource = RawItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
