<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Listings matched to the lead (SPEC 11), read-only.
 */
class MatchedListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'matchedListings';

    protected static ?string $title = 'Căn hộ khớp';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->emptyStateHeading('Chưa có căn khớp')
            ->columns([
                TextColumn::make('match_score')->label('Độ khớp')->badge()
                    ->color(fn ($state) => $state >= 60 ? 'success' : 'warning'),
                TextColumn::make('title')->label('Căn hộ')
                    ->url(fn ($record) => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('area_slug')->label('Khu vực'),
                TextColumn::make('property_type')->label('Loại'),
                TextColumn::make('price_usd')->label('Giá')->suffix(' $'),
                TextColumn::make('min_term_months')->label('Thuê tối thiểu')->suffix(' tháng'),
                IconColumn::make('pet_friendly')->label('Thú cưng')->boolean(),
                TextColumn::make('features')->label('Tiện ích')->badge()->color('gray'),
            ]);
    }
}
