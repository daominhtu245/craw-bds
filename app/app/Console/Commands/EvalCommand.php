<?php

namespace App\Console\Commands;

use App\Enums\LeadIntent;
use App\Services\Ai\LeadClassifier;
use App\Services\Ai\UsageCost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs the hand-written eval set through the REAL AI (SPEC 18.3).
 * This is the only command allowed to call the Anthropic API outside the queue.
 */
#[Signature('leadradar:eval
    {--file=tests/Fixtures/samples.jsonl : JSONL eval file relative to the app root}
    {--limit=0 : Only run the first N samples (0 = all)}
    {--min-precision=0.85 : Required tenant_demand precision}
    {--min-recall=0.80 : Required tenant_demand recall}')]
#[Description('Evaluate the AI classifier on the labelled samples (calls the real API)')]
class EvalCommand extends Command
{
    public function handle(LeadClassifier $classifier, UsageCost $cost): int
    {
        $path = (string) $this->option('file');
        if (! is_file($path)) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $samples = array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        ), 'is_array'));

        if ((int) $this->option('limit') > 0) {
            $samples = array_slice($samples, 0, (int) $this->option('limit'));
        }

        $labels = LeadIntent::values();
        $matrix = array_fill_keys($labels, array_fill_keys([...$labels, 'error'], 0));
        $stageHits = 0;
        $stageTotal = 0;
        $usd = 0.0;
        $mistakes = [];

        $bar = $this->output->createProgressBar(count($samples));

        foreach ($samples as $i => $sample) {
            $expected = (string) $sample['expected_intent'];

            try {
                $run = $classifier->classifyText(
                    (string) $sample['text'],
                    $sample['context'] ?? null,
                    Carbon::parse($sample['posted_at'] ?? 'now')->utc(),
                    (string) ($sample['source'] ?? 'Eval samples'),
                );
                $predicted = $run->classification->intent->value;
                $usd += $cost->usd($run->model, $run->inputTokens, $run->outputTokens);

                if ($expected === LeadIntent::TenantDemand->value && $predicted === $expected && isset($sample['expected_stage'])) {
                    $stageTotal++;
                    $stageHits += (int) ($run->classification->stage->value === $sample['expected_stage']);
                }
            } catch (Throwable $e) {
                $predicted = 'error';
                $this->newLine();
                $this->warn('Sample '.($i + 1).': '.$e->getMessage());
            }

            $matrix[$expected][$predicted] = ($matrix[$expected][$predicted] ?? 0) + 1;
            if ($predicted !== $expected) {
                $mistakes[] = [$i + 1, $expected, $predicted, mb_strimwidth((string) $sample['text'], 0, 70, '…')];
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Confusion matrix (rows = expected, columns = predicted)');
        $this->table(['expected \\ predicted', ...$labels, 'error'], array_map(
            fn (string $row) => [$row, ...array_values($matrix[$row])],
            $labels,
        ));

        $td = LeadIntent::TenantDemand->value;
        $tp = $matrix[$td][$td];
        $predictedTd = array_sum(array_column($matrix, $td));
        $actualTd = array_sum($matrix[$td]);
        $precision = $predictedTd > 0 ? $tp / $predictedTd : 0.0;
        $recall = $actualTd > 0 ? $tp / $actualTd : 0.0;

        if ($mistakes !== []) {
            $this->table(['#', 'expected', 'predicted', 'text'], $mistakes);
        }

        $this->line(sprintf('tenant_demand precision: %.3f (need ≥ %s)', $precision, $this->option('min-precision')));
        $this->line(sprintf('tenant_demand recall:    %.3f (need ≥ %s)', $recall, $this->option('min-recall')));
        if ($stageTotal > 0) {
            $this->line(sprintf('stage accuracy (correct tenant_demand): %.3f', $stageHits / $stageTotal));
        }
        $this->line(sprintf('estimated cost: $%.4f', $usd));

        $passed = $precision >= (float) $this->option('min-precision') && $recall >= (float) $this->option('min-recall');
        $passed ? $this->info('PASS') : $this->error('FAIL');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
