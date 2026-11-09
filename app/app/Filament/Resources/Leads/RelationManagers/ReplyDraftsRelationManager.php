<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Leads\LeadActions;
use App\Models\ReplyDraft;
use App\Services\Leads\LeadWorkflow;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Reply drafts (SPEC 12 / 14.2): edit, copy, regenerate. Never sent automatically.
 */
class ReplyDraftsRelationManager extends RelationManager
{
    protected static string $relationship = 'replyDrafts';

    protected static ?string $title = 'Bản nháp trả lời';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->label('Nội dung')->required()->rows(10)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có bản nháp')
            ->emptyStateDescription('Lead đủ điểm sẽ được AI soạn tự động. Bạn có thể bấm "Tạo lại bản nháp".')
            ->columns([
                TextColumn::make('variant')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'dm' ? 'Nhắn riêng (DM)' : 'Trả lời công khai'),
                TextColumn::make('body')
                    ->label('Nội dung (bấm để copy)')
                    ->wrap()
                    ->copyable()
                    ->copyMessage('Đã copy. Hãy dán và trả lời thủ công tại bài gốc.'),
                TextColumn::make('created_by')
                    ->label('Tạo bởi')
                    ->formatStateUsing(fn (string $state) => $state === 'system' ? 'AI' : 'Sale'),
                TextColumn::make('created_at')->label('Lúc')->since()->dateTimeTooltip(),
            ])
            ->headerActions([
                LeadActions::regenerateDrafts()->record(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Sửa')
                    ->mutateDataUsing(fn (array $data) => $data + ['created_by' => (string) auth()->id()]),
                Action::make('markReplied')
                    ->label('Đã trả lời')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Ghi nhận bạn đã copy bản nháp này và trả lời thủ công tại bài gốc.')
                    ->action(function (ReplyDraft $record) {
                        app(LeadWorkflow::class)->recordReplyCopied($this->getOwnerRecord(), auth()->user(), $record->variant);
                        Notification::make()->title('Đã ghi nhận')->success()->send();
                    }),
            ]);
    }
}
