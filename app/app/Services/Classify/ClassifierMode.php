<?php

namespace App\Services\Classify;

use App\Models\Setting;
use App\Services\Ai\AiBudget;
use App\Services\Ops\Alerter;

/**
 * Chooses the classification engine: AI (Claude) or keyword rules.
 *
 * Modes (settings.classifier_mode, default config leadradar.classifier.mode):
 *  - auto:    AI when an API key is set and today's budget is not spent, rules otherwise
 *  - ai:      always AI; jobs pause when the budget is spent
 *  - keyword: always rules, never calls the API
 */
class ClassifierMode
{
    public const AUTO = 'auto';

    public const AI = 'ai';

    public const KEYWORD = 'keyword';

    public const MODES = [self::AUTO, self::AI, self::KEYWORD];

    public function __construct(
        private readonly AiBudget $budget,
        private readonly Alerter $alerter,
    ) {}

    public function mode(): string
    {
        $mode = (string) Setting::get('classifier_mode', config('leadradar.classifier.mode'));

        return in_array($mode, self::MODES, true) ? $mode : self::AUTO;
    }

    public function hasApiKey(): bool
    {
        return trim((string) config('leadradar.ai.api_key')) !== '';
    }

    /**
     * Whether this item should go to the AI. In "auto" a spent budget switches to
     * rules for the rest of the day, and the team gets one alert.
     */
    public function useAi(): bool
    {
        return match ($this->mode()) {
            self::KEYWORD => false,
            self::AI => true,
            default => $this->hasApiKey() && ! $this->budgetSpentInAuto(),
        };
    }

    /**
     * How reply drafts are written: "ai" or "template". Templates are used in
     * keyword mode, without a key, and in "auto" once the budget is spent.
     * In "ai" mode the draft job pauses on a spent budget instead.
     */
    public function draftEngine(): string
    {
        if ($this->mode() === self::KEYWORD || ! $this->hasApiKey()) {
            return 'template';
        }

        if ($this->mode() === self::AUTO && $this->budget->exceeded()) {
            return 'template';
        }

        return 'ai';
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::AI => 'Chỉ AI',
            self::KEYWORD => 'Chỉ keyword (không cần API key)',
            default => 'Tự động (AI nếu có key và còn ngân sách, không thì keyword)',
        };
    }

    private function budgetSpentInAuto(): bool
    {
        if (! $this->budget->exceeded()) {
            return false;
        }

        $day = now(config('leadradar.display_timezone'))->toDateString();
        $this->alerter->raise(
            "ai-budget-auto:{$day}",
            sprintf('Đã dùng hết ngân sách AI hôm nay ($%.2f / $%.2f). Chuyển sang phân loại bằng keyword đến 0h.', $this->budget->spentToday(), $this->budget->limit()),
        );

        return true;
    }
}
