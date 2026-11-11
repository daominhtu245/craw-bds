<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\FailedJobs\Pages\ListFailedJobs;
use App\Jobs\NotifyLeadJob;
use App\Models\FailedJob;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

class FailedJobsPageTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['queue.default' => 'database']);
    }

    /** A real job payload moved to failed_jobs, as the worker would do. */
    private function failedJob(): FailedJob
    {
        Queue::connection('database')->push(new NotifyLeadJob(123));
        $job = DB::table('jobs')->latest('id')->first();
        DB::table('jobs')->delete();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'notify',
            'payload' => $job->payload,
            'exception' => "RuntimeException: Telegram sendMessage failed: 400 Bad Request: chat not found\n#0 /var/www/stack/trace.php(1)",
            'failed_at' => now(),
        ]);

        return FailedJob::query()->latest('id')->first();
    }

    public function test_lists_failed_jobs_and_retries_one(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        $failed = $this->failedJob();

        Livewire::test(ListFailedJobs::class)
            ->assertSee('NotifyLeadJob')
            ->assertSee('chat not found')
            ->assertDontSee('stack/trace.php')
            ->callTableAction('retry', $failed)
            ->assertNotified('Đã đưa job vào hàng đợi');

        $this->assertSame(0, FailedJob::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_deletes_a_failed_job_and_retries_all(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        $first = $this->failedJob();
        $this->failedJob();

        Livewire::test(ListFailedJobs::class)->callTableAction('forget', $first);
        $this->assertSame(1, FailedJob::count());

        Livewire::test(ListFailedJobs::class)->callAction(\Filament\Actions\Testing\TestAction::make('retryAll')->table());
        $this->assertSame(0, FailedJob::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_sales_cannot_open_it(): void
    {
        $this->actingAs($this->user(UserRole::Sales))->get('/admin/failed-jobs')->assertForbidden();
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/failed-jobs')->assertOk();
    }
}
