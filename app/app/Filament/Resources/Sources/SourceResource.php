<?php

namespace App\Filament\Resources\Sources;

use App\Enums\SourceType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Sources\Pages\ManageSources;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Sources with health info (SPEC 14.3): last_item_at, items and leads in 7 days.
 */
class SourceResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Nguồn';

    protected static ?string $modelLabel = 'nguồn';

    protected static ?string $pluralModelLabel = 'nguồn';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')->label('Loại')->options(SourceType::class)->required(),
                TextInput::make('name')->label('Tên hiển thị')->required()->maxLength(255),
                TextInput::make('identifier')->label('Định danh (username / URL)')->required()->maxLength(255)
                    ->helperText('Telegram: username hoặc ID chat. Facebook/Forum: URL. Reddit: tên subreddit, không có r/ (vd: danang). Threads: từ khóa tìm kiếm (vd: da nang apartment).'),
                TextInput::make('language_hint')->label('Ngôn ngữ chính')->maxLength(8)->placeholder('en, ru…'),
                Textarea::make('rules_note')->label('Luật của group (hiển thị cho sale)')->rows(3)->columnSpanFull(),
                TextInput::make('meta.maxItems')->label('Số bài tối đa mỗi lần (Facebook)')->numeric()->minValue(1)->maxValue(500),
                TextInput::make('meta.query')->label('Chỉ lấy bài khớp từ khóa (Reddit, tùy chọn)')->maxLength(255)
                    ->placeholder('vd: da nang rent')->helperText('Dùng cho subreddit rộng như VietNam: chỉ đọc kết quả tìm kiếm mới nhất trong subreddit.'),
                Toggle::make('meta.important')->label('Quan trọng: cảnh báo nếu 24 giờ không có item mới'),
                Toggle::make('is_active')->label('Đang bật')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Few sources, so per-row counts are cheap and keep the table query simple.
        $since = fn () => now()->subDays(7);

        return $table
            ->columns([
                TextColumn::make('name')->label('Tên')->searchable()->description(fn (Source $record) => $record->identifier),
                TextColumn::make('type')->label('Loại')->badge(),
                TextColumn::make('language_hint')->label('Ngôn ngữ')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Bật'),
                TextColumn::make('last_item_at')->label('Item mới nhất')->since()->dateTimeTooltip()->placeholder('Chưa có')->sortable()
                    ->color(fn (Source $record) => $record->last_item_at === null || $record->last_item_at->lt(now()->subDay()) ? 'danger' : 'success'),
                TextColumn::make('items_7d')->label('Item 7 ngày')
                    ->state(fn (Source $record) => $record->rawItems()->where('created_at', '>=', $since())->count()),
                TextColumn::make('leads_7d')->label('Lead 7 ngày')
                    ->state(fn (Source $record) => $record->rawItems()->where('created_at', '>=', $since())->whereHas('lead')->count()),
                TextColumn::make('rules_note')->label('Luật group')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Loại')->options(SourceType::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSources::route('/'),
        ];
    }
}
