<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use App\Services\Classify\ClassifierMode;
use App\Services\Drafts\TemplateDrafter;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                Section::make('Thông báo')
                    ->columns(2)
                    ->schema([
                        TextInput::make('notify_min_score')->label('Điểm tối thiểu để báo Telegram')
                            ->numeric()->minValue(0)->maxValue(100)->required(),
                        TextInput::make('quiet_hours')->label('Giờ yên lặng (giờ VN, ví dụ 23-07)')
                            ->regex('/^([01]?\d|2[0-3])-([01]?\d|2[0-3])$/')->required(),
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
        Setting::put('notify_min_score', (int) $data['notify_min_score']);
        Setting::put('quiet_hours', (string) $data['quiet_hours']);
        Setting::put('classifier_mode', (string) $data['classifier_mode']);
        Setting::put('agency_name', (string) $data['agency_name']);
        Setting::put('reply_templates', collect($data['templates'])->map(fn ($variants) => collect($variants)->map(fn ($t) => (string) $t)->all())->all());
        Setting::put('agency_website', (string) ($data['agency_website'] ?? ''));

        Notification::make()->title('Đã lưu cài đặt')->success()->send();
    }
}
