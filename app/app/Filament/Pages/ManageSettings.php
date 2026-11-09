<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Settings (SPEC 14.3): FX rates, thresholds, quiet hours, agency info.
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
            'agency_name' => Setting::get('agency_name'),
            'agency_website' => Setting::get('agency_website'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
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
        Setting::put('agency_name', (string) $data['agency_name']);
        Setting::put('agency_website', (string) ($data['agency_website'] ?? ''));

        Notification::make()->title('Đã lưu cài đặt')->success()->send();
    }
}
