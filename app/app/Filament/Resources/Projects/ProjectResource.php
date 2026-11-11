<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Projects\Pages\ManageProjects;
use App\Models\Area;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Projects (buildings) shown on the public site, e.g. Masteri (App #2).
 */
class ProjectResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Dự án';

    protected static ?string $modelLabel = 'dự án';

    protected static ?string $pluralModelLabel = 'dự án';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label('Tên dự án')->required()->maxLength(255),
                TextInput::make('slug')->label('Đường dẫn (slug)')->maxLength(100)->alphaDash()
                    ->unique(ignoreRecord: true)->helperText('Để trống để tạo tự động từ tên.'),
                Select::make('area_slug')->label('Khu vực')
                    ->options(fn () => Area::query()->orderBy('name_en')->pluck('name_vi', 'slug')->all()),
                Toggle::make('is_active')->label('Hiển thị')->default(true),
                Textarea::make('description.vi')->label('Mô tả (tiếng Việt)')->rows(4)->maxLength(3000)->columnSpanFull(),
                Textarea::make('description.en')->label('Mô tả (tiếng Anh)')->rows(4)->maxLength(3000)->columnSpanFull(),
                Textarea::make('description.ru')->label('Mô tả (tiếng Nga)')->rows(4)->maxLength(3000)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên')->searchable()->description(fn (Project $record) => $record->slug),
                TextColumn::make('area_slug')->label('Khu vực')->badge()->color('gray'),
                TextColumn::make('listings_count')->label('Số căn')->counts('listings'),
                IconColumn::make('is_active')->label('Hiển thị')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProjects::route('/'),
        ];
    }
}
