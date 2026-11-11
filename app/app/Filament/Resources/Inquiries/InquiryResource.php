<?php

namespace App\Filament\Resources\Inquiries;

use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\LeadFormat;
use App\Models\Inquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Requests from the public site / Telegram Mini App (App #2). Read-only, no export
 * (CLAUDE.md). Contacts are shown to sales, like on the lead page.
 */
class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Yêu cầu website';

    protected static ?string $modelLabel = 'yêu cầu';

    protected static ?string $pluralModelLabel = 'yêu cầu từ website / Mini App';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Inquiry::query()->where('created_at', '>=', now()->subDay())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Yêu cầu trong 24 giờ qua';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Khách tự gửi qua trang căn hộ hoặc Telegram Mini App. Họ tên và liên hệ được mã hóa và tự xóa sau '
                .config('leadradar.site.contact_retention_days').' ngày.')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['listing', 'lead']))
            ->recordUrl(fn (Inquiry $record) => $record->lead_id ? LeadResource::getUrl('view', ['record' => $record->lead_id]) : null)
            ->columns([
                TextColumn::make('created_at')->label('Gửi lúc')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (Inquiry $record) => $record->created_at->since()),
                TextColumn::make('source')->label('Nguồn')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'telegram_miniapp' ? 'Mini App' : 'Website')
                    ->color(fn (string $state) => $state === 'telegram_miniapp' ? 'info' : 'success'),
                TextColumn::make('locale')->label('Ngôn ngữ')->formatStateUsing(fn (string $state) => LeadFormat::language($state)),
                TextColumn::make('name')->label('Họ tên')->placeholder('Đã xóa theo hạn lưu'),
                TextColumn::make('contact')->label('Liên hệ')->copyable()
                    ->formatStateUsing(fn (?string $state, Inquiry $record) => ucfirst($record->contact_channel).': '.$state)
                    ->placeholder('Đã xóa theo hạn lưu'),
                TextColumn::make('listing.title')->label('Căn quan tâm')->placeholder('—')->wrap(),
                TextColumn::make('details')->label('Nhu cầu')
                    ->state(fn (Inquiry $record) => implode(' · ', array_filter([
                        $record->arrival_date ? 'Đến '.$record->arrival_date->format('d/m/Y') : null,
                        $record->duration_months ? $record->duration_months.' tháng' : null,
                        $record->budget_usd ? '≤ '.$record->budget_usd.'$' : null,
                    ])))
                    ->placeholder('—'),
                TextColumn::make('lead.status')->label('Trạng thái lead')->badge()->placeholder('—'),
                TextColumn::make('ref')->label('Từ link bản nháp')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => $state ? 'Có' : null)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')->label('Nguồn')->options(['website' => 'Website', 'telegram_miniapp' => 'Mini App']),
                SelectFilter::make('locale')->label('Ngôn ngữ')->options(['vi' => 'Tiếng Việt', 'en' => 'English', 'ru' => 'Русский']),
                TernaryFilter::make('contact_purged_at')->label('Liên hệ đã xóa')->nullable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
        ];
    }
}
