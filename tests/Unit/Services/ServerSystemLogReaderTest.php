<?php

namespace Tests\Unit\Services\ServerSystemLogReaderTest;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Services\Servers\ServerSystemLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('fetch returns unknown for invalid dynamic site key', function () {
    $server = Server::factory()->ready()->create();

    $result = app(ServerSystemLogReader::class)->fetch($server, 'site_not_a_ulid_access');

    expect($result['output'])->toBe('');
    expect($result['error'])->toBe(__('Unknown log source.'));
});

test('fetch site platform key returns merged activity', function () {
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

    expect($result['error'])->toBeNull();
    $this->assertStringContainsString('audit', $result['output']);
    $this->assertStringContainsString('site.updated', $result['output']);
    $this->assertStringContainsString('deploy', $result['output']);
    $this->assertStringContainsString('manual', $result['output']);
});

test('dply activity log eager loads audit subjects', function () {
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

    AuditLog::log($org, $user, 'server.updated', $server, null, ['name' => 'Renamed']);
    AuditLog::log($org, $user, 'site.updated', $site, null, ['name' => 'Renamed']);

    $server->load('sites');

    DB::enableQueryLog();
    $output = app(ServerSystemLogReader::class)->dplyActivityLog($server, 50);
    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, '"servers"') || str_contains($sql, '"sites"'))
        ->count();
    DB::disableQueryLog();

    expect($output)->toContain('server.updated')
        ->and($output)->toContain('site.updated')
        ->and($queries)->toBeLessThanOrEqual(2);
});

test('journal sources are defined', function () {
    $sources = config('server_system_logs.sources', []);

    expect($sources)->toHaveKey('journal_nginx');
    expect($sources['journal_nginx']['type'] ?? null)->toBe('journal');
    expect(config('server_system_logs.journal_allowed_units', []))->not->toBeEmpty();
});
