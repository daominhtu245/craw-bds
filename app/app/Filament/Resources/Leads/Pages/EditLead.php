<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\ActivityType;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $changed = array_keys($this->getRecord()->getChanges());
        $changed = array_values(array_diff($changed, ['updated_at']));

        if ($changed !== []) {
            $this->getRecord()->activities()->create([
                'user_id' => auth()->id(),
                'type' => ActivityType::Note,
                'body' => 'Sửa tay thông tin: '.implode(', ', $changed),
                'meta' => ['fields' => $changed],
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
