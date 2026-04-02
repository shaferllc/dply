<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Servers\ServerSystemLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerSystemLogReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_returns_unknown_for_invalid_dynamic_site_key(): void
    {
        $server = Server::factory()->ready()->create();

        $result = app(ServerSystemLogReader::class)->fetch($server, 'site_not_a_ulid_access');

        $this->assertSame('', $result['output']);
        $this->assertSame(__('Unknown log source.'), $result['error']);
    }

    public function test_fetch_site_platform_key_returns_merged_activity(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $user->organizations()->attach($org->id);
        $server = Server::factory()->ready()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        AuditLog::log($org, $user, 'site.updated', $site, null, ['name' => 'Renamed']);

        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'git_sha' => 'deadbeef',
            'log_output' => 'ok',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $key = 'site_'.$site->getKey().'_platform';
        $result = app(ServerSystemLogReader::class)->fetch($server, $key);

        $this->assertNull($result['error']);
        $this->assertStringContainsString('audit', $result['output']);
        $this->assertStringContainsString('site.updated', $result['output']);
        $this->assertStringContainsString('deploy', $result['output']);
        $this->assertStringContainsString('manual', $result['output']);
    }

    public function test_journal_sources_are_defined(): void
    {
        $sources = config('server_system_logs.sources', []);

        $this->assertArrayHasKey('journal_nginx', $sources);
        $this->assertSame('journal', $sources['journal_nginx']['type'] ?? null);
        $this->assertNotEmpty(config('server_system_logs.journal_allowed_units', []));
    }
}
