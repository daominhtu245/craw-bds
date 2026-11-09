<?php

namespace App\Filament\Resources\Areas;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Pages\PrefilterTester;
use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Models\Area;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AreaResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Khu vực';

    protected static ?string $modelLabel = 'khu vực';

    protected static ?string $pluralModelLabel = 'khu vực';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('slug')->label('Slug')->required()->maxLength(64)->alphaDash()->unique(ignoreRecord: true),
                TextInput::make('name_en')->label('Tên tiếng Anh')->required()->maxLength(255),
                TextInput::make('name_vi')->label('Tên tiếng Việt')->required()->maxLength(255),
                TagsInput::make('aliases')->label('Biến thể (EN, VI không dấu, RU, KO…)')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_vi')->label('Khu vực')->searchable()->description(fn (Area $record) => $record->slug),
                TextColumn::make('name_en')->label('Tên tiếng Anh')->searchable(),
                TextColumn::make('aliases')->label('Biến thể')->badge()->color('gray'),
            ])
            ->headerActions([
                Action::make('tester')->label('Test nhanh prefilter')->icon(Heroicon::OutlinedBeaker)
                    ->url(fn () => PrefilterTester::getUrl()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAreas::route('/'),
        ];
    }
}
