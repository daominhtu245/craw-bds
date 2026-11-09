<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadStage;
use App\Enums\PropertyType;
use App\Models\Area;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Manual correction of AI-extracted fields (SPEC 14.2). Status changes go
 * through the status action so they always produce an activity entry.
 */
class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sửa thông tin AI trích xuất')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Textarea::make('summary_vi')->label('Tóm tắt')->required()->maxLength(500)->rows(2)->columnSpanFull(),
                        Select::make('stage')->label('Giai đoạn')->options(LeadStage::class)->required(),
                        DatePicker::make('arrival_date')->label('Ngày đến'),
                        TextInput::make('duration_months')->label('Thời gian thuê (tháng)')->numeric()->minValue(1)->maxValue(120),
                        Select::make('property_type')->label('Loại nhà')->options(PropertyType::class)->required(),
                        TextInput::make('bedrooms_min')->label('Số phòng ngủ tối thiểu')->numeric()->minValue(0)->maxValue(20),
                        Select::make('areas')->label('Khu vực')->multiple()
                            ->options(fn () => Area::query()->orderBy('name_en')->pluck('name_vi', 'slug')->all()),
                        TextInput::make('budget_min_usd')->label('Ngân sách từ (USD)')->numeric()->minValue(0),
                        TextInput::make('budget_max_usd')->label('Ngân sách đến (USD)')->numeric()->minValue(0),
                        TextInput::make('household')->label('Hộ gia đình')->maxLength(255),
                        Select::make('requirements')->label('Yêu cầu')->multiple()
                            ->options(fn () => collect(config('leadradar.leads.requirement_tags'))->mapWithKeys(fn ($t) => [$t => $t])->all()),
                    ]),
            ]);
    }
}
