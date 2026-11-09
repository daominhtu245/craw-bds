<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Leads\LeadActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Activity timeline (SPEC 14.2).
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Lịch sử hoạt động';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Lúc')->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')->label('Người thực hiện')->placeholder('Hệ thống'),
                TextColumn::make('type')->label('Loại')->badge()
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'status_change' => 'Đổi trạng thái',
                        'note' => 'Ghi chú',
                        'reply_sent' => 'Đã trả lời',
                        'ai_draft' => 'AI soạn nháp',
                        default => 'Hệ thống',
                    }),
                TextColumn::make('body')->label('Nội dung')->wrap(),
            ])
            ->headerActions([
                LeadActions::addNote()->record(fn () => $this->getOwnerRecord()),
            ]);
    }
}
