<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\SourceType;
use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Support\LeadFormat;
use App\Models\Area;
use App\Models\Lead;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['rawItem.source', 'assignee']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('score')->orderByDesc('created_at'))
            ->poll('30s')
            ->columns([
                TextColumn::make('score')
                    ->label('Điểm')
                    ->badge()
                    ->color(fn (?int $state) => LeadFormat::scoreColor($state))
                    ->sortable(),
                TextColumn::make('summary_vi')
                    ->label('Tóm tắt')
                    ->wrap()
                    ->lineClamp(3)
                    ->description(fn (Lead $record) => $record->intent === LeadIntent::LeavingHandover ? '♻️ Nguồn hàng' : null)
                    ->searchable(),
                TextColumn::make('language')
                    ->label('Ngôn ngữ')
                    ->formatStateUsing(fn (?string $state) => LeadFormat::language($state)),
                TextColumn::make('stage')
                    ->label('Giai đoạn')
                    ->badge(),
                TextColumn::make('areas')
                    ->label('Khu vực')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('budget_max_usd')
                    ->label('Ngân sách')
                    ->state(fn (Lead $record) => LeadFormat::budget($record))
                    ->placeholder('—'),
                TextColumn::make('duration_months')
                    ->label('Thời gian thuê')
                    ->suffix(' tháng')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('rawItem.source.name')
                    ->label('Nguồn')
                    ->description(fn (Lead $record) => $record->rawItem?->source?->type?->getLabel()),
                TextColumn::make('rawItem.posted_at')
                    ->label('Đăng')
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label('Phụ trách')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(LeadStatus::class)
                    ->multiple(),
                Filter::make('score_min')
                    ->schema([TextInput::make('score_min')->label('Điểm ≥')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['score_min'] ?? null), fn ($q) => $q->where('score', '>=', (int) $data['score_min']))),
                SelectFilter::make('stage')
                    ->label('Giai đoạn')
                    ->options(LeadStage::class)
                    ->multiple(),
                SelectFilter::make('language')
                    ->label('Ngôn ngữ')
                    ->options(fn () => Lead::query()->distinct()->orderBy('language')->pluck('language', 'language')->map(fn ($l) => LeadFormat::language($l))->all()),
                Filter::make('areas')
                    ->schema([
                        Select::make('areas')
                            ->label('Khu vực')
                            ->multiple()
                            ->options(fn () => Area::query()->orderBy('name_en')->pluck('name_vi', 'slug')->all()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['areas'] ?? null), function (Builder $q) use ($data) {
                        $q->where(function (Builder $q) use ($data) {
                            foreach ($data['areas'] as $slug) {
                                $q->orWhereJsonContains('areas', $slug);
                            }
                        });
                    })),
                Filter::make('budget')
                    ->schema([
                        TextInput::make('budget_from')->label('Ngân sách từ ($)')->numeric(),
                        TextInput::make('budget_to')->label('Ngân sách đến ($)')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (filled($data['budget_from'] ?? null)) {
                            $query->whereRaw('COALESCE(budget_max_usd, budget_min_usd) >= ?', [(int) $data['budget_from']]);
                        }
                        if (filled($data['budget_to'] ?? null)) {
                            $query->whereRaw('COALESCE(budget_min_usd, budget_max_usd) <= ?', [(int) $data['budget_to']]);
                        }
                    }),
                Filter::make('duration_min')
                    ->schema([TextInput::make('duration_min')->label('Thời gian thuê ≥ (tháng)')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['duration_min'] ?? null), fn ($q) => $q->where('duration_months', '>=', (int) $data['duration_min']))),
                SelectFilter::make('source_type')
                    ->label('Loại nguồn')
                    ->options(SourceType::class)
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['value'] ?? null), fn ($q) => $q->whereHas('rawItem.source', fn ($s) => $s->where('type', $data['value'])))),
                SelectFilter::make('intent')
                    ->label('Loại lead')
                    ->options([
                        LeadIntent::TenantDemand->value => LeadIntent::TenantDemand->getLabel(),
                        LeadIntent::LeavingHandover->value => LeadIntent::LeavingHandover->getLabel(),
                    ]),
                SelectFilter::make('posted_within')
                    ->label('Thời gian đăng')
                    ->options(['6' => '6 giờ', '24' => '24 giờ', '72' => '3 ngày', '168' => '7 ngày'])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['value'] ?? null), fn ($q) => $q->whereHas('rawItem', fn ($r) => $r->where('posted_at', '>=', now()->subHours((int) $data['value']))))),
                Filter::make('mine')
                    ->label('Của tôi')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            ])
            ->recordActions([
                ViewAction::make(),
                LeadActions::claim(),
                ActionGroup::make([
                    LeadActions::changeStatus(),
                    LeadActions::markInvalid(),
                    LeadActions::markAgent(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
