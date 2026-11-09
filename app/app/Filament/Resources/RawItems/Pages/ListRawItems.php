<?php

namespace App\Filament\Resources\RawItems\Pages;

use App\Filament\Resources\RawItems\RawItemResource;
use App\Services\Privacy\AuthorDataEraser;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRawItems extends ListRecords
{
    protected static string $resource = RawItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // SPEC 15: delete everything about one person on request.
            Action::make('eraseAuthor')
                ->label('Xóa dữ liệu theo người đăng')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->schema([
                    TextInput::make('author_handle')
                        ->label('Username công khai của người đăng')
                        ->required()
                        ->maxLength(191)
                        ->helperText('Xóa mọi item, lead, bản nháp và lịch sử liên quan tới người này. Không thể hoàn tác.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Xóa toàn bộ dữ liệu người này')
                ->action(function (array $data) {
                    $result = app(AuthorDataEraser::class)->erase($data['author_handle'], auth()->id());

                    Notification::make()
                        ->title("Đã xóa {$result['raw_items']} item, {$result['leads']} lead")
                        ->success()
                        ->send();
                }),
        ];
    }
}
