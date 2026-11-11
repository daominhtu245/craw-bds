<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use App\Services\Classify\ClassifierMode;
use App\Services\Drafts\TemplateDrafter;
use App\Services\Notify\TelegramConfig;
use App\Services\Notify\TelegramNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Settings (SPEC 14.3): classifier mode, FX rates, thresholds, quiet hours, agency info.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    use AdminOnly;

    public const CURRENCIES = ['VND', 'RUB', 'EUR', 'KRW', 'AUD', 'GBP'];

    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Cài đặt';

    protected static ?string $title = 'Cài đặt';

    protected static ?int $navigationSort = 9;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $fx = (array) Setting::get('fx_per_usd', []);

        $this->form->fill([
            'fx' => collect(self::CURRENCIES)->mapWithKeys(fn ($c) => [$c => $fx[$c] ?? null])->all(),
            'notify_min_score' => Setting::get('notify_min_score', config('leadradar.notify.min_score')),
            'quiet_hours' => Setting::get('quiet_hours', config('leadradar.notify.quiet_hours')),
            'telegram_enabled' => app(TelegramConfig::class)->enabled(),
            'telegram_bot_token' => null,
            'telegram_chat_id' => Setting::get(TelegramConfig::CHAT_ID, ''),
            'classifier_mode' => app(ClassifierMode::class)->mode(),
            'agency_name' => Setting::get('agency_name'),
            'templates' => TemplateDrafter::templates(),
            'agency_website' => Setting::get('agency_website'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Phân loại tin')
                    ->description(fn () => app(ClassifierMode::class)->hasApiKey()
                        ? 'ANTHROPIC_API_KEY: đã cấu hình.'
                        : 'ANTHROPIC_API_KEY: chưa cấu hình. Chế độ Tự động sẽ dùng keyword; bản nháp AI không được tạo.')
                    ->schema([
                        Select::make('classifier_mode')->label('Chế độ phân loại')
                            ->options(collect(ClassifierMode::MODES)->mapWithKeys(fn ($m) => [$m => ClassifierMode::label($m)])->all())
                            ->helperText('Keyword: dùng các từ khóa nhóm "Phân loại: …" và "Giai đoạn: …" ở trang Từ khóa. Thử trước ở trang Test prefilter.')
                            ->required(),
                    ]),
                Section::make('Tỷ giá (số đơn vị ngoại tệ đổi được 1 USD)')
                    ->columns(3)
                    ->schema(collect(self::CURRENCIES)->map(
                        fn ($c) => TextInput::make("fx.{$c}")->label($c)->numeric()->minValue(0.0001)->required(),
                    )->all()),
                Section::make('Thông báo Telegram')
                    ->description('Lead mới đạt điểm tối thiểu (hoặc tin sang lại hợp đồng) được gửi ngay vào chat bên dưới. '
                        .'Chat là group hoặc channel; với channel, bot phải là admin của channel.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('telegram_enabled')->label('Bật thông báo Telegram')->columnSpanFull(),
                        TextInput::make('telegram_bot_token')->label('Bot token')
                            ->password()->revealable()->autocomplete('off')->maxLength(100)
                            ->regex('/^\d+:[A-Za-z0-9_-]{20,}$/')
                            ->placeholder(fn () => $this->tokenPlaceholder())
                            ->helperText('Lấy từ @BotFather. Để trống để giữ token hiện tại. Token được mã hóa khi lưu và không hiển thị lại.'),
                        TextInput::make('telegram_chat_id')->label('Chat ID')->maxLength(100)
                            ->regex('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{3,})$/')
                            ->placeholder(fn () => config('leadradar.notify.team_chat_id') ? 'Đang dùng TG_TEAM_CHAT_ID trong .env' : '-1001234567890 hoặc @ten_channel')
                            ->helperText('Group: số dạng -100…. Channel công khai: @ten_channel. Để trống để dùng TG_TEAM_CHAT_ID trong .env.'),
                        TextInput::make('notify_min_score')->label('Điểm tối thiểu để báo')
                            ->numeric()->minValue(0)->maxValue(100)->required(),
                        TextInput::make('quiet_hours')->label('Giờ yên lặng (giờ VN, ví dụ 23-07)')
                            ->regex('/^([01]?\d|2[0-3])-([01]?\d|2[0-3])$/')
                            ->helperText('Trong khung giờ này lead được gom thành 1 tin lúc hết giờ (lead gấp vẫn gửi ngay). Để trống để luôn báo ngay.'),
                        Actions::make([
                            Action::make('sendTelegramTest')->label('Gửi tin thử')->icon(Heroicon::OutlinedPaperAirplane)
                                ->action(fn () => $this->sendTelegramTest()),
                        ])->columnSpanFull(),
                    ]),
                Section::make('Template bản nháp trả lời (khi không dùng AI)')
                    ->description('Dùng ở chế độ keyword, khi chưa có API key, hoặc khi AI hết ngân sách/lỗi ở chế độ Tự động. '
                        .'Biến: {agency} {website} {area} {budget} {duration} {arrival} {listing} {listing_price} {listings}. '
                        .'Đoạn trong [[ ... ]] bị bỏ nếu có biến rỗng. Không nên để link trong trả lời công khai.')
                    ->collapsible()
                    ->columns(2)
                    ->schema(collect(['en' => 'Tiếng Anh', 'ru' => 'Tiếng Nga'])->flatMap(fn ($label, $lang) => [
                        Textarea::make("templates.{$lang}.public_reply")->label("{$label}: trả lời công khai")->rows(5)->required()->maxLength(2000),
                        Textarea::make("templates.{$lang}.dm")->label("{$label}: tin nhắn riêng")->rows(5)->required()->maxLength(2000),
                    ])->all()),
                Section::make('Thông tin agency (dùng trong bản nháp)')
                    ->columns(2)
                    ->schema([
                        TextInput::make('agency_name')->label('Tên agency')->required()->maxLength(100),
                        TextInput::make('agency_website')->label('Website')->url()->maxLength(255),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::put('fx_per_usd', collect($data['fx'])->map(fn ($v) => (float) $v)->all());
        Setting::put(TelegramConfig::ENABLED, (bool) $data['telegram_enabled']);
        if (trim((string) ($data['telegram_bot_token'] ?? '')) !== '') {
            app(TelegramConfig::class)->saveToken((string) $data['telegram_bot_token']);
        }
        Setting::put(TelegramConfig::CHAT_ID, trim((string) ($data['telegram_chat_id'] ?? '')));
        Setting::put('notify_min_score', (int) $data['notify_min_score']);
        Setting::put('quiet_hours', (string) ($data['quiet_hours'] ?? ''));
        Setting::put('classifier_mode', (string) $data['classifier_mode']);
        Setting::put('agency_name', (string) $data['agency_name']);
        Setting::put('reply_templates', collect($data['templates'])->map(fn ($variants) => collect($variants)->map(fn ($t) => (string) $t)->all())->all());
        Setting::put('agency_website', (string) ($data['agency_website'] ?? ''));

        $this->data['telegram_bot_token'] = null;

        Notification::make()->title('Đã lưu cài đặt')->success()->send();
    }

    /** Uses the token / chat id typed in the form, falling back to the saved ones. */
    public function sendTelegramTest(): void
    {
        $config = app(TelegramConfig::class);
        $token = trim((string) ($this->data['telegram_bot_token'] ?? '')) ?: $config->botToken();
        $chatId = trim((string) ($this->data['telegram_chat_id'] ?? '')) ?: $config->chatId();

        if ($token === '' || $chatId === '') {
            Notification::make()->title('Chưa có bot token hoặc chat ID')->danger()->send();

            return;
        }

        try {
            app(TelegramNotifier::class)->sendTest($token, $chatId);
        } catch (Throwable $e) {
            // Never echo the token back: the exception text may contain the request URL.
            $message = str_replace($token, '***', $e->getMessage());
            Notification::make()->title('Gửi thử thất bại')->body($message)->danger()->persistent()->send();

            return;
        }

        Notification::make()->title('Đã gửi tin thử, kiểm tra chat Telegram')->success()->send();
    }

    private function tokenPlaceholder(): string
    {
        $config = app(TelegramConfig::class);

        if ($config->hasStoredToken()) {
            return 'Đã lưu (để trống để giữ nguyên)';
        }

        return config('leadradar.notify.bot_token') ? 'Đang dùng TG_BOT_TOKEN trong .env' : '123456789:AA…';
    }
}
