<?php

namespace App\Filament\Resources\RawItems\Schemas;

use App\Models\RawItem;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RawItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Nội dung')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('text')->label('Văn bản')->prose(),
                        TextEntry::make('context_text')->label('Ngữ cảnh')->prose()->placeholder('—'),
                        TextEntry::make('url')->label('Link gốc')
                            ->url(fn (RawItem $record) => $record->url, shouldOpenInNewTab: true)->color('primary'),
                    ]),
                Section::make('Thông tin')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('source.name')->label('Nguồn'),
                        TextEntry::make('external_id')->label('ID gốc'),
                        TextEntry::make('parent_external_id')->label('ID bài cha')->placeholder('—'),
                        TextEntry::make('author_handle')->label('Người đăng')->placeholder('—'),
                        TextEntry::make('posted_at')->label('Đăng lúc')->dateTime(),
                        TextEntry::make('language')->label('Ngôn ngữ')->placeholder('—'),
                        TextEntry::make('status')->label('Trạng thái')->badge(),
                        TextEntry::make('filter_reason')->label('Lý do lọc')->badge()->placeholder('—'),
                        TextEntry::make('ai_model')->label('Model')->placeholder('—'),
                        TextEntry::make('ai_input_tokens')->label('Token vào / ra')
                            ->state(fn (RawItem $record) => $record->ai_model ? "{$record->ai_input_tokens} / {$record->ai_output_tokens}" : null)
                            ->placeholder('—'),
                    ]),
                Section::make('Kết quả AI (thô)')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('classification')->label('')
                            ->state(fn (RawItem $record) => collect((array) $record->classification)
                                ->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE))
                                ->all()),
                    ]),
            ]);
    }
}
