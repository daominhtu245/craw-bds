<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadIntent;
use App\Filament\Support\LeadFormat;
use App\Models\Lead;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Lead detail (SPEC 14.2). Drafts, matched listings and the activity timeline
 * are relation managers below this infolist.
 */
class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Tổng quan')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('score')->label('Điểm')->badge()->color(fn (?int $state) => LeadFormat::scoreColor($state)),
                        TextEntry::make('intent')->label('Loại')->badge(),
                        TextEntry::make('status')->label('Trạng thái')->badge(),
                        TextEntry::make('assignee.name')->label('Phụ trách')->placeholder('Chưa có'),
                        TextEntry::make('summary_vi')->label('Tóm tắt')->columnSpanFull(),
                    ]),

                Section::make('Bài gốc')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('rawItem.text')->label('Nội dung')->prose()->copyable(),
                        TextEntry::make('rawItem.context_text')->label('Ngữ cảnh (bài cha / tin được trả lời)')->prose()->placeholder('—'),
                        TextEntry::make('rawItem.url')
                            ->label('Link bài gốc')
                            ->url(fn (Lead $record) => $record->rawItem?->url, shouldOpenInNewTab: true)
                            ->color('primary'),
                        TextEntry::make('rawItem.author_handle')->label('Người đăng')->placeholder('—'),
                        TextEntry::make('rawItem.source.name')->label('Nguồn')
                            ->helperText(fn (Lead $record) => $record->rawItem?->source?->type?->getLabel()),
                        TextEntry::make('rawItem.posted_at')->label('Đăng lúc')->dateTime()->since()->dateTimeTooltip(),
                        TextEntry::make('rawItem.source.rules_note')
                            ->label('Luật của group')
                            ->placeholder('Chưa ghi luật')
                            ->color('warning'),
                    ]),

                Section::make('AI trích xuất')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('language')->label('Ngôn ngữ')->formatStateUsing(fn (?string $state) => LeadFormat::language($state)),
                        TextEntry::make('stage')->label('Giai đoạn')->badge(),
                        TextEntry::make('arrival_date')->label('Ngày đến')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('duration_months')->label('Thời gian thuê')->suffix(' tháng')->placeholder('—'),
                        TextEntry::make('property_type')->label('Loại nhà'),
                        TextEntry::make('bedrooms_min')->label('Số phòng ngủ tối thiểu')->placeholder('—'),
                        TextEntry::make('areas')->label('Khu vực')->badge()->color('gray')->placeholder('—'),
                        TextEntry::make('budget')->label('Ngân sách (USD/tháng)')
                            ->state(fn (Lead $record) => LeadFormat::budget($record))
                            ->helperText(fn (Lead $record) => $record->budget_raw ? 'Nguyên văn: '.$record->budget_raw : null)
                            ->placeholder('—'),
                        TextEntry::make('household')->label('Hộ gia đình')->placeholder('—'),
                        TextEntry::make('requirements')->label('Yêu cầu')->badge()->color('info')->placeholder('—'),
                        TextEntry::make('confidence')->label('Độ tin cậy AI'),
                        TextEntry::make('rawItem.ai_model')->label('Model')->placeholder('—'),
                    ]),
            ]);
    }

    public static function isHandover(Lead $lead): bool
    {
        return $lead->intent === LeadIntent::LeavingHandover;
    }
}
