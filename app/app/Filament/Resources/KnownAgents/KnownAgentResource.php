<?php

namespace App\Filament\Resources\KnownAgents;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\KnownAgents\Pages\ListKnownAgents;
use App\Models\KnownAgent;
use App\Services\Leads\KnownAgentRemover;
use App\Services\Text\TextNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Known agents (prefilter step 4): see who is on the list and take people off
 * it when they were marked by mistake.
 */
class KnownAgentResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = KnownAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Môi giới đã biết';

    protected static ?string $modelLabel = 'môi giới';

    protected static ?string $pluralModelLabel = 'môi giới đã biết';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Bài mới của những người này bị bỏ qua ở bước tiền lọc. Người được thêm khi sale bấm "Là môi giới" '
                .'hoặc tự động khi đăng nhiều tin cho thuê/môi giới. Gỡ khỏi danh sách nếu đánh dấu nhầm.')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('rawItems'))
            ->columns([
                TextColumn::make('handle')->label('Người đăng')
                    ->state(fn (KnownAgent $record) => $record->handle())
                    ->placeholder('Không rõ tên')
                    ->description(fn (KnownAgent $record) => $record->latestItem()?->source?->name),
                TextColumn::make('latest_post')->label('Bài gần nhất')->wrap()
                    ->state(fn (KnownAgent $record) => TextNormalizer::excerpt($record->latestItem()?->text, 140))
                    ->url(fn (KnownAgent $record) => $record->latestItem()?->url, shouldOpenInNewTab: true)
                    ->placeholder('Không còn bài nào (đã xóa theo hạn lưu)'),
                TextColumn::make('raw_items_count')->label('Số bài')->sortable(),
                TextColumn::make('reason')->label('Lý do')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => match (true) {
                        str_starts_with((string) $state, 'manual') => 'Sale đánh dấu',
                        str_starts_with((string) $state, 'auto') => 'Tự động',
                        default => (string) $state,
                    })
                    ->tooltip(fn (KnownAgent $record) => $record->reason),
                TextColumn::make('created_by')->label('Người thêm')
                    ->state(fn (KnownAgent $record) => $record->creatorName()),
                TextColumn::make('created_at')->label('Thêm lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Lý do')
                    ->options(['manual' => 'Sale đánh dấu', 'auto' => 'Tự động'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('reason', 'like', $data['value'].'%')
                        : $query),
            ])
            ->recordActions([
                Action::make('remove')->label('Gỡ khỏi danh sách')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Bài mới của người này sẽ không còn bị bỏ qua.')
                    ->schema([
                        Toggle::make('requeue')->label('Xử lý lại các bài đã bị lọc vì "môi giới"')->default(true)
                            ->helperText('Bài còn trong hạn sẽ đi lại qua tiền lọc và phân loại; bài quá cũ vẫn bị lọc.'),
                    ])
                    ->action(function (KnownAgent $record, array $data) {
                        $count = app(KnownAgentRemover::class)->remove($record, (bool) ($data['requeue'] ?? false));
                        Notification::make()->title('Đã gỡ khỏi danh sách')
                            ->body($count > 0 ? "Đã đưa {$count} bài vào xử lý lại." : null)
                            ->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnownAgents::route('/'),
        ];
    }
}
