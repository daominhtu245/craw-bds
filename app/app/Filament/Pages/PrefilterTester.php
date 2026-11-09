<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Services\Areas\AreaResolver;
use App\Services\Prefilter\Prefilter;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "Test nhanh" box for keywords and areas (SPEC 14.3): paste a text and see
 * what the text steps of the prefilter decide. No AI call, nothing stored.
 *
 * @property-read Schema $form
 */
class PrefilterTester extends Page
{
    use AdminOnly;

    protected string $view = 'filament.pages.prefilter-tester';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Test prefilter';

    protected static ?string $title = 'Test nhanh prefilter';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Textarea::make('text')->label('Dán đoạn văn bản')->rows(6)->required(),
            ]);
    }

    public function analyze(): void
    {
        $text = (string) $this->form->getState()['text'];
        $analysis = app(Prefilter::class)->analyzeText($text);

        // Areas: try every word window against the alias index.
        $words = preg_split('/\s+/u', AreaResolver::fold($text)) ?: [];
        $candidates = [];
        for ($size = 1; $size <= 3; $size++) {
            for ($i = 0; $i + $size <= count($words); $i++) {
                $candidates[] = implode(' ', array_slice($words, $i, $size));
            }
        }

        $this->result = [
            'passed' => $analysis->passed(),
            'reason' => $analysis->reason?->getLabel(),
            'reason_code' => $analysis->reason?->value,
            'language' => $analysis->language,
            'positive' => $analysis->positiveMatches,
            'negative' => $analysis->negativeMatches,
            'negative_score' => $analysis->negativeScore,
            'strong' => $analysis->hasStrongPositive,
            'housing' => $analysis->mentionsHousing,
            'areas' => app(AreaResolver::class)->resolveQuietly($candidates),
        ];
    }
}
