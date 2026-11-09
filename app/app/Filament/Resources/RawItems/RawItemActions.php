<?php

namespace App\Filament\Resources\RawItems;

use App\Models\RawItem;
use App\Services\Leads\LeadWorkflow;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RawItemActions
{
    public static function reclassify(): Action
    {
        return Action::make('reclassify')
            ->label('Phân loại lại')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription('Item sẽ được gửi lại cho AI (bỏ qua prefilter). Có tốn phí AI.')
            ->action(function (RawItem $record) {
                app(LeadWorkflow::class)->reclassify($record);
                Notification::make()->title('Đã đưa vào hàng đợi AI')->success()->send();
            });
    }
}
