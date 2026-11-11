<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use App\Services\Classify\ClassifierMode;
use App\Services\Drafts\TemplateDrafter;
use App\Services\Notify\TelegramConfig;
use App\Services\Notify\TelegramNotifier;
use App\Services\Settings\ConfigOverrides;
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
use Illuminate\Support\Arr;
use Throwable;

/**
 * Settings (SPEC 14.3): classifier mode, FX rates, thresholds, quiet hours, agency info,
 * plus the .env values listed in ConfigOverrides::FIELDS ("cfg.*"; empty = use .env).
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
            'site_contacts' => array_merge(['zalo' => null, 'telegram' => null, 'whatsapp' => null, 'phone' => null], (array) Setting::get('site_contacts', [])),
            'classifier_mode' => app(ClassifierMode::class)->mode(),
            'agency_name' => Setting::get('agency_name'),
            'templates' => TemplateDrafter::templates(),
            'agency_website' => Setting::get('agency_website'),
            'cfg' => Arr::undot(collect(ConfigOverrides::formValues())
                ->map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v)
                ->all()),
            'clear_api_key' => false,
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
                Section::make('AI (Claude)')
                    ->description('Ô để trống thì dùng giá trị trong .env (ghi ở gợi ý xám).')
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        TextInput::make('cfg.ai.api_key')->label('Anthropic API key')->columnSpan(2)
                            ->password()->revealable()->autocomplete('off')->maxLength(200)
                            ->regex('/^sk-ant-[A-Za-z0-9_-]{20,}$/')
                            ->placeholder(fn () => match (true) {
                                ConfigOverrides::hasStored('ai.api_key') => 'Đã lưu (để trống để giữ nguyên)',
                                filled(ConfigOverrides::default('ai.api_key')) => 'Đang dùng ANTHROPIC_API_KEY trong .env',
                                default => 'sk-ant-…',
                            })
                            ->helperText('Mã hóa khi lưu, không hiển thị lại. Có key thì chế độ Tự động dùng AI.'),
                        Toggle::make('clear_api_key')->label('Xóa key đã lưu (dùng .env)')
                            ->visible(fn () => ConfigOverrides::hasStored('ai.api_key')),
                        $this->modelSelect('ai.model_classify', 'Model phân loại'),
                        $this->modelSelect('ai.model_escalate', 'Model hỏi lại khi chưa chắc'),
                        $this->modelSelect('ai.model_draft', 'Model soạn bản nháp'),
                        $this->number('ai.daily_budget_usd', 'Ngân sách AI mỗi ngày (USD)', 0, 1000, 0.5),
                        $this->number('ai.min_confidence', 'Độ tin cậy tối thiểu (0–1)', 0, 1, 0.05)
                            ->helperText('Dưới mức này thì hỏi lại bằng model mạnh hơn.'),
                    ]),
                Section::make('Tiền lọc và lead')
                    ->description('Ô để trống thì dùng giá trị trong .env (ghi ở gợi ý xám).')
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        $this->onOff('prefilter.accept_vi', 'Nhận bài tiếng Việt'),
                        $this->number('prefilter.max_age_days', 'Bỏ bài cũ hơn (ngày)', 1, 365),
                        $this->number('prefilter.min_length', 'Bỏ bài ngắn hơn (ký tự)', 1, 500),
                        $this->number('prefilter.duplicate_window_days', 'Coi là trùng trong vòng (ngày)', 1, 365),
                        $this->number('prefilter.negative_score_threshold', 'Ngưỡng điểm từ khóa loại trừ', 1, 20)
                            ->helperText('Tổng trọng số từ khóa loại trừ từ mức này trở lên thì bỏ bài.'),
                        $this->number('prefilter.strong_positive_weight', 'Trọng số "từ khóa mạnh"', 1, 20)
                            ->helperText('Bài có từ khóa tích cực từ mức này không bị loại vì từ khóa loại trừ.'),
                        $this->number('leads.expires_after_days', 'Lead tự hết hạn sau (ngày)', 1, 3650),
                        $this->number('leads.known_agent_threshold', 'Tự đánh dấu môi giới sau (số tin)', 1, 100)
                            ->helperText('Người đăng từng ấy tin cho thuê/môi giới thì vào danh sách môi giới.'),
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
                Section::make('Website (App #2)')
                    ->description(fn () => 'Trang căn hộ công khai: '.route('site.index', ['locale' => 'vi'])
                        .' (cũng là link Telegram Mini App). Chọn căn hiển thị ở Kho căn hộ → "Hiển thị trên website". '
                        .'Các nút chat bên dưới hiện ở góc trang; để trống nút nào thì ẩn nút đó.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('site_contacts.zalo')->label('Zalo (số điện thoại)')->maxLength(30)->regex('/^[\d\s+().-]+$/'),
                        TextInput::make('site_contacts.telegram')->label('Telegram (username)')->maxLength(40)->regex('/^@?[A-Za-z][A-Za-z0-9_]{3,}$/'),
                        TextInput::make('site_contacts.whatsapp')->label('WhatsApp (số có mã quốc gia)')->maxLength(30)->regex('/^[\d\s+().-]+$/'),
                        TextInput::make('site_contacts.phone')->label('Số điện thoại')->maxLength(30)->regex('/^[\d\s+().-]+$/'),
                        $this->onOff('site.enabled', 'Bật trang công khai'),
                        Select::make('cfg.site.default_locale')->label('Ngôn ngữ mặc định')
                            ->options(['vi' => 'Tiếng Việt', 'en' => 'English', 'ru' => 'Русский'])
                            ->placeholder(fn () => 'Theo .env ('.ConfigOverrides::default('site.default_locale').')'),
                        $this->number('site.per_page', 'Số căn mỗi trang', 1, 100),
                        $this->number('site.inquiry_score', 'Điểm lead từ form', 0, 100),
                        $this->number('site.contact_retention_days', 'Giữ liên hệ của khách (ngày)', 1, 3650)
                            ->helperText('Sau đó họ tên và liên hệ bị xóa (lệnh dọn dữ liệu hằng ngày).'),
                        $this->number('site.inquiry_rate_limit_per_hour', 'Số yêu cầu tối đa mỗi giờ / IP', 1, 1000),
                        $this->number('site.min_fill_seconds', 'Thời gian điền form tối thiểu (giây)', 0, 60)
                            ->helperText('Gửi nhanh hơn bị coi là bot.'),
                    ]),
                Section::make('Template bản nháp trả lời (khi không dùng AI)')
                    ->description('Dùng ở chế độ keyword, khi chưa có API key, hoặc khi AI hết ngân sách/lỗi ở chế độ Tự động. '
                        .'Biến: {agency} {website} {area} {budget} {duration} {arrival} {listing} {listing_price} {listings} {site_link} (link trang căn hộ có mã theo dõi). '
                        .'Đoạn trong [[ ... ]] bị bỏ nếu có biến rỗng. Không nên để link trong trả lời công khai.')
                    ->collapsible()
                    ->columns(2)
                    ->schema(collect(['vi' => 'Tiếng Việt', 'en' => 'Tiếng Anh', 'ru' => 'Tiếng Nga'])->flatMap(fn ($label, $lang) => [
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
        Setting::put('site_contacts', collect($data['site_contacts'] ?? [])->map(fn ($v) => trim((string) $v))->filter()->all());

        ConfigOverrides::save(
            collect(array_keys(ConfigOverrides::FIELDS))->mapWithKeys(fn ($key) => [$key => data_get($data['cfg'] ?? [], $key)])->all(),
            ! empty($data['clear_api_key']) ? ['ai.api_key'] : [],
        );

        $this->data['telegram_bot_token'] = null;
        $this->data['cfg']['ai']['api_key'] = null;
        $this->data['clear_api_key'] = false;

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

    /** Number field for a ConfigOverrides key; empty = .env value (shown as placeholder). */
    private function number(string $key, string $label, float $min, float $max, float $step = 1): TextInput
    {
        return TextInput::make('cfg.'.$key)->label($label)->numeric()->minValue($min)->maxValue($max)->step($step)
            ->placeholder(fn () => 'Theo .env: '.ConfigOverrides::default($key));
    }

    /** On / off / "use .env" for a boolean ConfigOverrides key. */
    private function onOff(string $key, string $label): Select
    {
        return Select::make('cfg.'.$key)->label($label)
            ->options(['1' => 'Bật', '0' => 'Tắt'])
            ->placeholder(fn () => 'Theo .env ('.(ConfigOverrides::default($key) ? 'Bật' : 'Tắt').')');
    }

    private function modelSelect(string $key, string $label): Select
    {
        return Select::make('cfg.'.$key)->label($label)
            ->options(fn () => collect(array_keys((array) config('leadradar.ai.pricing')))->mapWithKeys(fn ($m) => [$m => $m])->all())
            ->placeholder(fn () => 'Theo .env ('.ConfigOverrides::default($key).')');
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
