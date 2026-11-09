<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeadActions::claim(),
            LeadActions::changeStatus(),
            LeadActions::addNote(),
            ActionGroup::make([
                LeadActions::regenerateDrafts(),
                LeadActions::markInvalid(),
                LeadActions::markAgent(),
                EditAction::make()->label('Sửa thông tin'),
            ]),
        ];
    }
}
