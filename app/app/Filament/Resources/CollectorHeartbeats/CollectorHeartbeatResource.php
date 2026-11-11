<?php

namespace App\Filament\Resources\CollectorHeartbeats;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CollectorHeartbeats\Pages\ListCollectorHeartbeats;
use App\Models\CollectorHeartbeat;
use App\Services\Ops\HealthChecker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Collector health (SPEC 13 heartbeat): same rule as HealthChecker, visible
 * without a Telegram bot.
 */
class CollectorHeartbeatResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = CollectorHeartbeat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Tình trạng collector';

    protected static ?string $modelLabel = 'collector';

    protected static ?string $pluralModelLabel = 'tình trạng collector';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function status(CollectorHeartbeat $beat): string
    {
        if ($beat->last_seen_at->lt(now()->subMinutes(HealthChecker::HEARTBEAT_MAX_MINUTES))) {
            return 'stale';
        }

        return $beat->ok ? 'ok' : 'error';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Mỗi collector gửi heartbeat sau mỗi vòng chạy. Quá '.HealthChecker::HEARTBEAT_MAX_MINUTES
                .' phút không gửi, hoặc báo lỗi (ví dụ Facebook/Reddit yêu cầu đăng nhập lại), là có vấn đề. '
                .'Collector chạy theo vòng dài (Facebook mặc định 60 phút) sẽ hiện "Im lặng" giữa các vòng.')
            ->defaultSort('collector')
            ->emptyStateHeading('Chưa có collector nào gửi heartbeat')
            ->emptyStateDescription('Chạy một collector (ví dụ .\run-local.ps1 forum.expat_com --once) rồi tải lại trang.')
            ->poll('30s')
            ->columns([
                TextColumn::make('collector')->label('Collector')->weight('bold'),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->state(fn (CollectorHeartbeat $record) => self::status($record))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ok' => 'Hoạt động',
                        'error' => 'Báo lỗi',
                        default => 'Im lặng',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success',
                        'error' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('last_seen_at')->label('Lần cuối')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('details')->label('Chi tiết')->wrap()
                    ->state(fn (CollectorHeartbeat $record) => collect((array) $record->meta)
                        ->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))
                        ->implode(' · '))
                    ->placeholder('—'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollectorHeartbeats::route('/'),
        ];
    }
}
