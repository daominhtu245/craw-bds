<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Jobs\ClassifyItemJob;
use App\Models\KnownAgent;
use App\Services\Leads\LeadWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

class LeadWorkflowTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    public function test_claim_assigns_and_moves_new_to_assigned(): void
    {
        $lead = $this->makeLead();
        $sale = $this->user();

        app(LeadWorkflow::class)->claim($lead, $sale);

        $this->assertSame($sale->id, $lead->fresh()->assigned_to);
        $this->assertSame(LeadStatus::Assigned, $lead->fresh()->status);
        $this->assertSame(ActivityType::StatusChange, $lead->activities()->first()->type);
    }

    public function test_claim_keeps_later_status(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Viewing]);

        app(LeadWorkflow::class)->claim($lead, $this->user());

        $this->assertSame(LeadStatus::Viewing, $lead->fresh()->status);
    }

    public function test_lost_and_invalid_require_a_note(): void
    {
        $lead = $this->makeLead();
        $sale = $this->user();

        foreach ([LeadStatus::Lost, LeadStatus::Invalid] as $status) {
            try {
                app(LeadWorkflow::class)->changeStatus($lead, $status, $sale, '  ');
                $this->fail('Expected validation error');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('note', $e->errors());
            }
        }

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
        $this->assertSame(0, $lead->activities()->count());
    }

    public function test_status_change_is_logged_and_auto_assigns(): void
    {
        $lead = $this->makeLead();
        $sale = $this->user();

        app(LeadWorkflow::class)->changeStatus($lead, LeadStatus::Contacted, $sale);
        app(LeadWorkflow::class)->changeStatus($lead, LeadStatus::Lost, $sale, 'Đã thuê chỗ khác');

        $lead->refresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertSame($sale->id, $lead->assigned_to);
        $activities = $lead->activities()->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame('contacted', $activities[1]->meta['from']);
        $this->assertStringContainsString('Đã thuê chỗ khác', $activities[1]->body);
    }

    public function test_mark_as_agent_adds_known_agent_and_invalidates(): void
    {
        $lead = $this->makeLead();

        app(LeadWorkflow::class)->markAsAgent($lead, $this->user());

        $this->assertTrue(KnownAgent::query()->where('author_key', $lead->rawItem->author_key)->exists());
        $this->assertSame(LeadStatus::Invalid, $lead->fresh()->status);
    }

    public function test_notes_and_reply_copied_are_logged(): void
    {
        $lead = $this->makeLead();
        $sale = $this->user();

        app(LeadWorkflow::class)->addNote($lead, $sale, 'Khách hẹn gọi video tối nay');
        app(LeadWorkflow::class)->recordReplyCopied($lead, $sale, 'public_reply');

        $this->assertSame(['note', 'reply_sent'], $lead->activities()->orderBy('id')->get()->map(fn ($a) => $a->type->value)->all());

        $this->expectException(ValidationException::class);
        app(LeadWorkflow::class)->addNote($lead, $sale, ' ');
    }

    public function test_reclassify_requeues_item(): void
    {
        Queue::fake();
        $lead = $this->makeLead();

        app(LeadWorkflow::class)->reclassify($lead->rawItem);

        $this->assertSame(RawItemStatus::Queued, $lead->rawItem->fresh()->status);
        Queue::assertPushedOn('ai', ClassifyItemJob::class);
    }
}
