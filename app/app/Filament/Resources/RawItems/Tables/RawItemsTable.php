<?php

namespace App\Filament\Resources\RawItems\Tables;

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use App\Enums\SourceType;
use App\Filament\Resources\RawItems\RawItemActions;
use App\Models\RawItem;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RawItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['source', 'lead']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('text')->label('Nội dung')->limit(160)->extraAttributes(['style' => 'min-width: 20rem'])->wrap()->lineClamp(4)->searchable(),
                TextColumn::make('source.name')->label('Nguồn')
                    ->description(fn (RawItem $record) => $record->source?->type?->getLabel()),
                TextColumn::make('author_handle')->label('Người đăng')->placeholder('—')->searchable()->visibleFrom('lg'),
                TextColumn::make('language')->label('Ngôn ngữ')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('status')->label('Trạng thái')->badge(),
                TextColumn::make('filter_reason')->label('Lý do lọc')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('ai_intent')->label('AI')->badge()->placeholder('—')
                    ->state(fn (RawItem $record) => $record->classification['intent'] ?? null),
                TextColumn::make('lead.id')->label('Lead')->placeholder('—')
                    ->url(fn (RawItem $record) => $record->lead ? route('filament.admin.resources.leads.view', $record->lead) : null),
                TextColumn::make('posted_at')->label('Đăng')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(RawItemStatus::class)->multiple(),
                SelectFilter::make('filter_reason')->label('Lý do lọc')->options(FilterReason::class)->multiple(),
                SelectFilter::make('source_type')->label('Loại nguồn')->options(SourceType::class)
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['value'] ?? null), fn ($q) => $q->whereHas('source', fn ($s) => $s->where('type', $data['value'])))),
                SelectFilter::make('source_id')->label('Nguồn')->relationship('source', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                RawItemActions::reclassify(),
            ]);
    }
}
