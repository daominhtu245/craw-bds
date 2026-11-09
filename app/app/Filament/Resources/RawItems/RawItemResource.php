<?php

namespace App\Filament\Resources\RawItems;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\RawItems\Pages\ListRawItems;
use App\Filament\Resources\RawItems\Pages\ViewRawItem;
use App\Filament\Resources\RawItems\Schemas\RawItemInfolist;
use App\Filament\Resources\RawItems\Tables\RawItemsTable;
use App\Models\RawItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Lookup of every collected item (SPEC 14.3). Read-only except "Phân loại lại".
 */
class RawItemResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = RawItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Dữ liệu thô';

    protected static ?string $modelLabel = 'item';

    protected static ?string $pluralModelLabel = 'items';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return RawItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RawItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRawItems::route('/'),
            'view' => ViewRawItem::route('/{record}'),
        ];
    }
}
