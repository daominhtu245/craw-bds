<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * Every admin page renders for the roles that may see it, and is refused to the others.
 */
class AdminSmokeTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public static function pages(): array
    {
        // uri => sales may open it
        return [
            'dashboard' => ['/admin', true],
            'leads' => ['/admin/leads', true],
            'lead view' => ['/admin/leads/{lead}', true],
            'lead edit' => ['/admin/leads/{lead}/edit', true],
            'listings' => ['/admin/listings', true],
            'inquiries' => ['/admin/inquiries', true],
            'profile' => ['/admin/profile', true],
            'known agents' => ['/admin/known-agents', false],
            'collectors' => ['/admin/collector-heartbeats', false],
            'failed jobs' => ['/admin/failed-jobs', false],
            'projects' => ['/admin/projects', false],
            'sources' => ['/admin/sources', false],
            'keywords' => ['/admin/keywords', false],
            'areas' => ['/admin/areas', false],
            'raw items' => ['/admin/raw-items', false],
            'raw item view' => ['/admin/raw-items/{item}', false],
            'users' => ['/admin/users', false],
            'settings' => ['/admin/manage-settings', false],
            'prefilter tester' => ['/admin/prefilter-tester', false],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_for_allowed_roles(string $uri, bool $salesAllowed): void
    {
        $lead = $this->makeLead();
        $uri = str_replace(['{lead}', '{item}'], [$lead->id, $lead->raw_item_id], $uri);

        $this->actingAs($this->user(UserRole::Admin))->get($uri)->assertOk();

        $sales = $this->actingAs($this->user(UserRole::Sales))->get($uri);
        $salesAllowed ? $sales->assertOk() : $sales->assertForbidden();
    }
}
