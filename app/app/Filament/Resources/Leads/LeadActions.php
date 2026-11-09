<?php

namespace App\Filament\Resources\Leads;

use App\Enums\LeadStatus;
use App\Jobs\DraftReplyJob;
use App\Models\Lead;
use App\Services\Classify\ClassifierMode;
use App\Services\Leads\LeadWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Quick actions shared by the lead table and the lead page (SPEC 14.1 / 14.2).
 * All logic lives in LeadWorkflow; these only collect input.
 */
final class LeadActions
{
    public static function claim(): Action
    {
        return Action::make('claim')
            ->label('Nhận lead')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('primary')
            ->visible(fn (Lead $record) => $record->assigned_to !== auth()->id())
            ->requiresConfirmation(fn (Lead $record) => $record->assigned_to !== null)
            ->modalDescription(fn (Lead $record) => $record->assigned_to !== null ? 'Lead này đang có người phụ trách. Bạn muốn nhận lại?' : null)
            ->action(function (Lead $record) {
                app(LeadWorkflow::class)->claim($record, auth()->user());
                Notification::make()->title('Đã nhận lead')->success()->send();
            });
    }

    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Đổi trạng thái')
            ->icon(Heroicon::OutlinedArrowPath)
            ->fillForm(fn (Lead $record) => ['status' => $record->status])
            ->schema([
                Select::make('status')
                    ->label('Trạng thái')
                    ->options(LeadStatus::class)
                    ->required()
                    ->live(),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->rows(3)
                    ->required(fn ($get) => self::statusRequiresNote($get('status')))
                    ->helperText('Bắt buộc với "Mất khách" và "Không hợp lệ".'),
            ])
            ->action(function (Lead $record, array $data) {
                $status = $data['status'] instanceof LeadStatus ? $data['status'] : LeadStatus::from($data['status']);
                app(LeadWorkflow::class)->changeStatus($record, $status, auth()->user(), $data['note'] ?? null);
                Notification::make()->title('Đã cập nhật trạng thái')->success()->send();
            });
    }

    public static function markInvalid(): Action
    {
        return Action::make('markInvalid')
            ->label('Không hợp lệ')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Lead $record) => $record->status !== LeadStatus::Invalid)
            ->schema([
                Textarea::make('note')
                    ->label('Lý do (dùng để cải thiện prompt)')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Lead $record, array $data) {
                app(LeadWorkflow::class)->changeStatus($record, LeadStatus::Invalid, auth()->user(), $data['note']);
                Notification::make()->title('Đã đánh dấu không hợp lệ')->success()->send();
            });
    }

    public static function markAgent(): Action
    {
        return Action::make('markAgent')
            ->label('Là môi giới')
            ->icon(Heroicon::OutlinedBriefcase)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Tác giả sẽ được thêm vào danh sách môi giới; bài sau của họ bị lọc trước khi gọi AI.')
            ->action(function (Lead $record) {
                app(LeadWorkflow::class)->markAsAgent($record, auth()->user());
                Notification::make()->title('Đã thêm vào danh sách môi giới')->success()->send();
            });
    }

    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Thêm ghi chú')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->schema([
                Textarea::make('note')->label('Ghi chú')->required()->rows(3),
            ])
            ->action(function (Lead $record, array $data) {
                app(LeadWorkflow::class)->addNote($record, auth()->user(), $data['note']);
                Notification::make()->title('Đã lưu ghi chú')->success()->send();
            });
    }

    public static function regenerateDrafts(): Action
    {
        return Action::make('regenerateDrafts')
            ->label('Tạo lại bản nháp')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('AI sẽ soạn lại 2 bản nháp mới. Bản cũ vẫn được giữ.')
            ->action(function (Lead $record) {
                if (! app(ClassifierMode::class)->hasApiKey()) {
                    Notification::make()->title('Chưa cấu hình ANTHROPIC_API_KEY nên không soạn được bản nháp AI')->warning()->send();

                    return;
                }
                DraftReplyJob::dispatch($record->id, force: true);
                Notification::make()->title('Đang soạn bản nháp mới, tải lại sau ít giây')->info()->send();
            });
    }

    private static function statusRequiresNote(mixed $status): bool
    {
        $status = $status instanceof LeadStatus ? $status : LeadStatus::tryFrom((string) $status);

        return (bool) $status?->requiresNote();
    }
}
