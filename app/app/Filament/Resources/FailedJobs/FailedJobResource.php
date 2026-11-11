<?php

namespace App\Filament\Resources\FailedJobs;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\FailedJobs\Pages\ListFailedJobs;
use App\Models\FailedJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

/**
 * Failed queue jobs with retry / delete (uses queue:retry and queue:forget).
 * On the VPS Horizon shows the same thing at /horizon.
 */
class FailedJobResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = FailedJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Job bị lỗi';

    protected static ?string $modelLabel = 'job bị lỗi';

    protected static ?string $pluralModelLabel = 'job bị lỗi';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Job đã thử hết số lần mà vẫn lỗi (ví dụ gửi Telegram thất bại, AI lỗi). Sửa nguyên nhân rồi bấm "Chạy lại".')
            ->defaultSort('failed_at', 'desc')
            ->emptyStateHeading('Không có job nào bị lỗi')
            ->columns([
                TextColumn::make('failed_at')->label('Lỗi lúc')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (FailedJob $record) => $record->failed_at?->since()),
                TextColumn::make('job')->label('Job')->weight('bold')
                    ->state(fn (FailedJob $record) => $record->jobName()),
                TextColumn::make('queue')->label('Hàng đợi')->badge()->color('gray'),
                TextColumn::make('error')->label('Lỗi')->wrap()
                    ->state(fn (FailedJob $record) => $record->errorSummary()),
            ])
            ->headerActions([
                Action::make('retryAll')->label('Chạy lại tất cả')->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->visible(fn () => FailedJob::query()->exists())
                    ->action(function () {
                        Artisan::call('queue:retry', ['id' => ['all']]);
                        Notification::make()->title('Đã đưa tất cả job lỗi vào hàng đợi')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('retry')->label('Chạy lại')->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->title('Đã đưa job vào hàng đợi')->success()->send();
                    }),
                Action::make('forget')->label('Xóa')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:forget', ['id' => $record->uuid]);
                        Notification::make()->title('Đã xóa job lỗi')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedJobs::route('/'),
        ];
    }
}
