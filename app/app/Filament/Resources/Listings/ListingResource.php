<?php

namespace App\Filament\Resources\Listings;

use App\Enums\PropertyType;
use App\Filament\Resources\Listings\Pages\ManageListings;
use App\Models\Area;
use App\Models\Listing;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Apartment inventory (SPEC 5.4 / 14.3). Sales can view, admins edit.
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Kho căn hộ';

    protected static ?string $modelLabel = 'căn hộ';

    protected static ?string $pluralModelLabel = 'căn hộ';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label('Tên căn')->required()->maxLength(255)->columnSpanFull(),
                Select::make('area_slug')->label('Khu vực')->required()
                    ->options(fn () => Area::query()->orderBy('name_en')->pluck('name_vi', 'slug')->all()),
                Select::make('property_type')->label('Loại')->options(PropertyType::class)->required(),
                TextInput::make('bedrooms')->label('Số phòng ngủ')->required()->numeric()->minValue(0)->default(1),
                TextInput::make('price_usd')->label('Giá (USD/tháng)')->required()->numeric()->minValue(1),
                TextInput::make('min_term_months')->label('Thuê tối thiểu (tháng)')->required()->numeric()->minValue(1)->default(3),
                DatePicker::make('available_from')->label('Trống từ ngày'),
                Toggle::make('pet_friendly')->label('Cho nuôi thú cưng'),
                Toggle::make('furnished')->label('Đủ nội thất')->default(true),
                Select::make('features')->label('Tiện ích')->multiple()->columnSpanFull()
                    ->options(fn () => collect(config('leadradar.leads.requirement_tags'))->mapWithKeys(fn ($t) => [$t => $t])->all()),
                TextInput::make('url')->label('Link')->url()->maxLength(1024)->columnSpanFull(),
                Toggle::make('is_active')->label('Đang cho thuê')->default(true),
                Section::make('Website (App #2)')
                    ->description('Thông tin hiển thị trên trang căn hộ công khai và Telegram Mini App.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Toggle::make('is_public')->label('Hiển thị trên website')->columnSpanFull(),
                        Select::make('project_id')->label('Dự án')
                            ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        TextInput::make('slug')->label('Đường dẫn (slug)')->maxLength(150)->alphaDash()
                            ->unique(ignoreRecord: true)->helperText('Để trống để tạo tự động.'),
                        TextInput::make('size_m2')->label('Diện tích (m²)')->numeric()->minValue(1)->maxValue(2000),
                        FileUpload::make('photos')->label('Ảnh')->image()->multiple()->reorderable()
                            ->disk('public')->directory('listings')->visibility('public')
                            ->maxFiles(20)->maxSize(5120)->panelLayout('grid')->columnSpanFull()
                            ->helperText('Tối đa 20 ảnh, mỗi ảnh ≤ 5 MB. Ảnh đầu tiên là ảnh đại diện. Kéo để đổi thứ tự.'),
                        Tabs::make('content')->columnSpanFull()->tabs(collect(['vi' => 'Tiếng Việt', 'en' => 'English', 'ru' => 'Русский'])
                            ->map(fn (string $label, string $locale) => Tab::make($label)->schema([
                                TextInput::make("content.{$locale}.title")->label('Tiêu đề')->maxLength(150),
                                Textarea::make("content.{$locale}.description")->label('Mô tả')->rows(5)->maxLength(5000),
                                Textarea::make("content.{$locale}.policy")->label('Chính sách (cọc, thanh toán, phí…)')->rows(3)->maxLength(2000),
                            ]))->values()->all()),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('price_usd')
            ->columns([
                TextColumn::make('title')->label('Tên căn')->searchable()
                    ->url(fn (Listing $record) => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('area_slug')->label('Khu vực')->badge()->color('gray')->searchable(),
                TextColumn::make('property_type')->label('Loại')->badge(),
                TextColumn::make('bedrooms')->label('PN')->sortable(),
                TextColumn::make('price_usd')->label('Giá')->suffix(' $')->sortable(),
                TextColumn::make('min_term_months')->label('Tối thiểu')->suffix(' tháng')->sortable(),
                IconColumn::make('pet_friendly')->label('Thú cưng')->boolean(),
                IconColumn::make('furnished')->label('Nội thất')->boolean(),
                TextColumn::make('features')->label('Tiện ích')->badge()->color('gray')->toggleable(),
                TextColumn::make('available_from')->label('Trống từ')->date('d/m/Y')->sortable(),
                IconColumn::make('is_active')->label('Đang cho thuê')->boolean(),
                IconColumn::make('is_public')->label('Website')->boolean(),
                TextColumn::make('project.name')->label('Dự án')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('area_slug')->label('Khu vực')->multiple()
                    ->options(fn () => Area::query()->orderBy('name_en')->pluck('name_vi', 'slug')->all()),
                SelectFilter::make('property_type')->label('Loại')->options(PropertyType::class),
                TernaryFilter::make('is_active')->label('Đang cho thuê'),
                TernaryFilter::make('is_public')->label('Hiển thị trên website'),
                SelectFilter::make('project_id')->label('Dự án')->relationship('project', 'name'),
                TernaryFilter::make('pet_friendly')->label('Cho nuôi thú cưng'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageListings::route('/'),
        ];
    }
}
