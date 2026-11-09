<?php

namespace App\Filament\Resources\RawItems\Pages;

use App\Filament\Resources\RawItems\RawItemActions;
use App\Filament\Resources\RawItems\RawItemResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRawItem extends ViewRecord
{
    protected static string $resource = RawItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RawItemActions::reclassify(),
        ];
    }
}
