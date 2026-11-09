<?php

namespace App\Services\Ai;

use App\Enums\ActivityType;
use App\Enums\LeadIntent;
use App\Enums\LeadStatus;
use App\Models\KnownAgent;
use App\Models\Lead;
use App\Models\RawItem;
use App\Services\Areas\AreaResolver;
use App\Services\Classify\ClassifierMode;
use App\Services\Classify\RuleClassifier;
use App\Services\Currency\CurrencyConverter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI classification and post-processing (SPEC 9).
 *
 * classifyText() only talks to the model (used by leadradar:eval).
 * classify() picks AI or keyword rules (ClassifierMode), stores the result and
 * creates the lead when it qualifies.
 */
class LeadClassifier
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly PromptRepository $prompts,
        private readonly AreaResolver $areas,
        private readonly ClassifierMode $mode,
        private readonly RuleClassifier $rules,
    ) {}

    public function classifyText(string $text, ?string $context, CarbonInterface $postedAt, string $sourceName): ClassificationRun
    {
        $system = $this->prompts->render('classify', ['posted_at' => $postedAt->toIso8601String()]);
        $user = $this->userMessage($text, $context, $postedAt, $sourceName);
        $tool = ClassificationSchema::tool();
        $ai = config('leadradar.ai');

        $first = $this->client->callTool($ai['model_classify'], $system, $user, $tool, $ai['effort_classify'], $ai['max_tokens_classify']);
        $classification = Classification::fromToolInput($first->input);
        $inputTokens = $first->inputTokens;
        $outputTokens = $first->outputTokens;
        $model = $first->model;

        if ($classification->confidence < $ai['min_confidence'] && $ai['model_escalate'] !== $ai['model_classify']) {
            $second = $this->client->callTool($ai['model_escalate'], $system, $user, $tool, $ai['effort_classify'], $ai['max_tokens_classify']);
            $classification = Classification::fromToolInput($second->input);
            $inputTokens += $second->inputTokens;
            $outputTokens += $second->outputTokens;
            $model = $second->model;
        }

        return new ClassificationRun($classification, $model, $inputTokens, $outputTokens);
    }

    /**
     * @return Lead|null The lead when one was created (or already existed), null otherwise.
     */
    public function classify(RawItem $item): ?Lead
    {
        $item->loadMissing('source');
        $run = $this->run($item);
        $c = $run->classification;

        $item->forceFill([
            'classification' => $c->raw,
            'ai_model' => $run->model,
            'ai_input_tokens' => $run->inputTokens,
            'ai_output_tokens' => $run->outputTokens,
            // status becomes "classified" in ClassifyItemJob once the whole pipeline succeeded.
        ])->save();

        Log::info('classify.done', [
            'job' => 'ClassifyItemJob',
            'item_id' => $item->id,
            'intent' => $c->intent->value,
            'confidence' => $c->confidence,
            'model' => $run->model,
        ]);

        if (in_array($c->intent, [LeadIntent::Agent, LeadIntent::LandlordOffer], true)) {
            $this->trackAgent($item);
        }

        if (! $c->intent->createsLead() || $c->confidence < (float) config('leadradar.ai.min_confidence')) {
            return null;
        }

        return $this->createLead($item, $c);
    }

    /** AI or keyword rules, depending on ClassifierMode. In "auto" an AI error falls back to rules. */
    private function run(RawItem $item): ClassificationRun
    {
        if (! $this->mode->useAi()) {
            return $this->rules->classifyText($item->text, $item->context_text, $item->posted_at);
        }

        try {
            return $this->classifyText($item->text, $item->context_text, $item->posted_at, $item->source->name);
        } catch (ClaudeException $e) {
            if ($this->mode->mode() !== ClassifierMode::AUTO) {
                throw $e;
            }

            Log::warning('classify.ai_failed_fallback_rules', ['item_id' => $item->id, 'error' => $e->getMessage()]);

            return $this->rules->classifyText($item->text, $item->context_text, $item->posted_at);
        }
    }

    private function createLead(RawItem $item, Classification $c): Lead
    {
        [$budgetMin, $budgetMax] = $c->budget === null
            ? [null, null]
            : CurrencyConverter::fromSettings()->monthlyUsdRange($c->budget['min'], $c->budget['max'], $c->budget['currency'], $c->budget['period'], $c->durationMonths);

        $allowedTags = (array) config('leadradar.leads.requirement_tags');
        $requirements = array_values(array_intersect(array_map('strtolower', $c->requirements), $allowedTags));

        return DB::transaction(function () use ($item, $c, $budgetMin, $budgetMax, $requirements) {
            $lead = Lead::query()->firstOrCreate(['raw_item_id' => $item->id], [
                'intent' => $c->intent,
                'stage' => $c->stage,
                'language' => $c->language,
                'summary_vi' => $c->summaryVi !== '' ? $c->summaryVi : mb_substr($item->text, 0, 200),
                'arrival_date' => $c->arrivalDate,
                'duration_months' => $c->durationMonths,
                'property_type' => $c->propertyType,
                'bedrooms_min' => $c->bedroomsMin,
                'areas' => $this->areas->resolve($c->areasMentioned),
                'budget_min_usd' => $budgetMin,
                'budget_max_usd' => $budgetMax,
                'budget_raw' => $c->budget['raw'] ?? null,
                'household' => $c->household,
                'requirements' => $requirements,
                'confidence' => round($c->confidence, 2),
                'score' => 0,
                'status' => LeadStatus::New,
                'expires_at' => $item->posted_at->copy()->addDays((int) config('leadradar.leads.expires_after_days')),
            ]);

            if ($lead->wasRecentlyCreated) {
                $lead->activities()->create([
                    'type' => ActivityType::System,
                    'body' => ($c->raw['engine'] ?? null) === 'keyword' ? 'Lead được tạo tự động bằng keyword.' : 'Lead được tạo tự động bởi AI.',
                    'meta' => ['model' => $item->ai_model, 'confidence' => $c->confidence],
                ]);
            }

            return $lead;
        });
    }

    /** SPEC 9.4 step 5: authors with >= N agent/landlord items become known agents. */
    private function trackAgent(RawItem $item): void
    {
        if ($item->author_key === null) {
            return;
        }

        $count = RawItem::query()
            ->where('author_key', $item->author_key)
            ->where(function ($q) {
                $q->where('classification->intent', LeadIntent::Agent->value)
                    ->orWhere('classification->intent', LeadIntent::LandlordOffer->value);
            })
            ->count();

        if ($count >= (int) config('leadradar.leads.known_agent_threshold')) {
            KnownAgent::query()->firstOrCreate(
                ['author_key' => $item->author_key],
                ['reason' => "auto: {$count} agent/landlord posts", 'created_by' => 'system'],
            );
        }
    }

    private function userMessage(string $text, ?string $context, CarbonInterface $postedAt, string $sourceName): string
    {
        $parts = [
            'posted_at: '.$postedAt->toIso8601String(),
            'source: '.$sourceName,
        ];

        if ($context !== null && trim($context) !== '') {
            $parts[] = "context:\n<context>\n".$context."\n</context>";
        }

        $parts[] = "message:\n<message>\n".$text."\n</message>";

        return implode("\n\n", $parts);
    }
}
