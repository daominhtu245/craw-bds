<?php

namespace App\Filament\Resources\Keywords;

use App\Enums\KeywordKind;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Pages\PrefilterTester;
use App\Filament\Resources\Keywords\Pages\ManageKeywords;
use App\Models\Keyword;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class KeywordResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Keyword::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Keyword';

    protected static ?string $modelLabel = 'keyword';

    protected static ?string $pluralModelLabel = 'keyword';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('term')->label('Từ khóa')->required()->maxLength(255)->columnSpanFull(),
                Select::make('kind')->label('Loại')->options(KeywordKind::class)->required(),
                TextInput::make('language')->label('Ngôn ngữ')->required()->maxLength(8)->default('en'),
                TextInput::make('weight')->label('Trọng số')->required()->numeric()->minValue(1)->maxValue(10)->default(1)
                    ->helperText('Keyword dương có trọng số ≥ 2 là "mạnh".'),
                Toggle::make('is_regex')->label('Là regex'),
                Toggle::make('is_active')->label('Đang bật')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('kind')
            ->columns([
                TextColumn::make('term')->label('Từ khóa')->searchable(),
                TextColumn::make('kind')->label('Loại')->badge(),
                TextColumn::make('language')->label('Ngôn ngữ')->badge()->color('gray'),
                TextColumn::make('weight')->label('Trọng số')->sortable(),
                IconColumn::make('is_regex')->label('Regex')->boolean(),
                ToggleColumn::make('is_active')->label('Bật'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Loại')->options(KeywordKind::class),
                SelectFilter::make('language')->label('Ngôn ngữ')->options(['en' => 'en', 'ru' => 'ru']),
            ])
            ->headerActions([
                Action::make('tester')->label('Test nhanh prefilter')->icon(Heroicon::OutlinedBeaker)
                    ->url(fn () => PrefilterTester::getUrl()),
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
            'index' => ManageKeywords::route('/'),
        ];
    }
}
