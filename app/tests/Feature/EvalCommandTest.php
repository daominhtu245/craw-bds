<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class EvalCommandTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    public function test_eval_file_has_required_composition(): void
    {
        $samples = array_map(fn ($l) => json_decode($l, true), file(base_path('tests/Fixtures/samples.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $counts = array_count_values(array_column($samples, 'expected_intent'));

        $this->assertGreaterThanOrEqual(60, count($samples));
        $this->assertGreaterThanOrEqual(20, $counts['tenant_demand']);
        $this->assertGreaterThanOrEqual(5, $counts['leaving_handover']);
        $this->assertGreaterThanOrEqual(15, ($counts['landlord_offer'] ?? 0) + ($counts['agent'] ?? 0));
        $this->assertGreaterThanOrEqual(5, $counts['short_term']);
        $this->assertGreaterThanOrEqual(5, $counts['other_city']);
        $this->assertGreaterThanOrEqual(10, $counts['other']);

        foreach ($samples as $s) {
            $this->assertArrayHasKey('text', $s);
            $this->assertArrayHasKey('context', $s);
            $this->assertArrayHasKey('posted_at', $s);
            $this->assertArrayHasKey('expected_stage', $s);
        }

        $tenantStages = array_values(array_unique(array_column(array_filter($samples, fn ($s) => $s['expected_intent'] === 'tenant_demand'), 'expected_stage')));
        $this->assertEqualsCanonicalizing(['pre_arrival', 'in_city_searching', 'urgent', 'unknown'], $tenantStages);
    }

    public function test_eval_reports_metrics_and_exit_code(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eval');
        file_put_contents($file, implode("\n", [
            json_encode(['text' => 'a', 'context' => null, 'posted_at' => '2026-11-01T00:00:00Z', 'expected_intent' => 'tenant_demand', 'expected_stage' => 'pre_arrival']),
            json_encode(['text' => 'b', 'context' => null, 'posted_at' => '2026-11-01T00:00:00Z', 'expected_intent' => 'tenant_demand', 'expected_stage' => 'unknown']),
            json_encode(['text' => 'c', 'context' => null, 'posted_at' => '2026-11-01T00:00:00Z', 'expected_intent' => 'landlord_offer', 'expected_stage' => 'unknown']),
        ]));

        // Predictions: tenant(pre_arrival) ✓, tenant ✓ (stage wrong), landlord ✗ predicted tenant.
        $this->fakeClaude(['tenant_demand', 'tenant_demand', 'tenant_demand']);

        $this->artisan('leadradar:eval', ['--file' => $file])
            ->expectsOutputToContain('tenant_demand precision: 0.667')
            ->expectsOutputToContain('tenant_demand recall:    1.000')
            ->expectsOutputToContain('stage accuracy (correct tenant_demand): 0.500')
            ->expectsOutputToContain('FAIL')
            ->assertFailed();

        @unlink($file);
    }
}
