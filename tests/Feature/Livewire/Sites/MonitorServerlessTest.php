<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\MonitorServerlessTest;

use App\Livewire\Sites\Monitor;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Server, 2: Site} */
function makeFunctionsSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => []],
    ]);

    return [$user, $server, $site];
}
test('function monitor renders the activity section', function () {
    [$user, $server, $site] = makeFunctionsSite();

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site])
        ->assertSee('Function activity')
        // No invocations yet — the empty state, not the cards.
        ->assertSee('No invocations in this window yet');
});
test('activity section reflects recorded invocations', function () {
    [$user, $server, $site] = makeFunctionsSite();
    foreach ([100, 200, 300] as $i => $duration) {
        FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => FunctionInvocation::SOURCE_WEB,
            'method' => 'GET',
            'path' => '/',
            'status_code' => 200,
            'success' => true,
            'duration_ms' => $duration,
            'cold' => $i === 0,
            'log_lines' => [],
            'created_at' => now()->subMinutes($i * 5),
        ]);
    }

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site])
        ->assertSee('Function activity')
        ->assertDontSee('No invocations in this window yet')
        ->assertSee('Invocations')
        ->assertSee('p95 duration');
});
test('set stats range rejects unknown ranges', function () {
    [$user, $server, $site] = makeFunctionsSite();

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site])
        ->call('setStatsRange', '1h')
        ->assertSet('statsRange', '1h')
        ->call('setStatsRange', 'bogus')
        ->assertSet('statsRange', '24h');
});
test('a function without a monitor gets a homepage check', function () {
    Queue::fake();
    [$user, $server, $site] = makeFunctionsSite();

    // Simulate a function created before the Site::created uptime hook.
    SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();

    Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);

    $monitor = SiteUptimeMonitor::query()->where('site_id', $site->id)->first();
    expect($monitor)->not->toBeNull();
    expect($monitor->label)->toBe('Homepage check');
    expect(array_keys(config('site_uptime.probe_regions')))->toContain($monitor->probe_region);

    // Idempotent — a second visit must not add a duplicate.
    Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);
    expect(SiteUptimeMonitor::query()->where('site_id', $site->id)->count())->toBe(1);
});
test('the uptime resolver finds a function url', function () {
    [, , $site] = makeFunctionsSite();
    $site->update(['meta' => array_merge((array) $site->meta, [
        'serverless' => ['action_url' => 'https://faas.example/api/v1/web/ns/default/fn'],
    ])]);

    $url = app(SiteUptimeCheckUrlResolver::class)->resolveBaseUrl($site->fresh());

    // A function resolves a public URL — it no longer reports "no URL".
    expect($url)->not->toBeNull();
    expect((string) $url)->toStartWith('https://');
});
test('a vm site monitor has no function section', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site])
        ->assertDontSee('Function activity');
});
