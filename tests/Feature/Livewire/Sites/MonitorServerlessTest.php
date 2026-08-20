<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\MonitorServerlessTest;

use App\Livewire\Sites\Monitor;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Modules\Serverless\Models\FunctionInvocation;
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
test('a function without a monitor gets an http and https homepage pair', function () {
    Queue::fake();
    [$user, $server, $site] = makeFunctionsSite();

    // Simulate a function created before the Site::created uptime hook.
    SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();

    Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);

    $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->orderBy('sort_order')->get();
    expect($monitors)->toHaveCount(2);
    expect($monitors->pluck('label')->all())->toBe(['Homepage (HTTPS)', 'Homepage (HTTP)']);
    expect($monitors->pluck('check_type')->all())->toBe([
        SiteUptimeMonitor::CHECK_HTTPS,
        SiteUptimeMonitor::CHECK_HTTP,
    ]);
    expect($monitors->every(fn (SiteUptimeMonitor $monitor): bool => $monitor->probe_region === 'us-east'))->toBeTrue();

    // Idempotent — a second visit must not add a duplicate.
    Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);
    expect(SiteUptimeMonitor::query()->where('site_id', $site->id)->count())->toBe(2);
});
test('a legacy homepage check is retargeted to https and gains an http sibling', function () {
    Queue::fake();
    [$user, $server, $site] = makeFunctionsSite();

    SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();
    SiteUptimeMonitor::query()->create([
        'site_id' => $site->id,
        'label' => 'Homepage check',
        'check_type' => SiteUptimeMonitor::CHECK_HTTP,
        'path' => null,
        'probe_region' => 'eu-falkenstein',
        'probe_worker' => 'worker-1',
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);

    $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->orderBy('sort_order')->get();
    expect($monitors)->toHaveCount(2);
    expect($monitors->firstWhere('check_type', SiteUptimeMonitor::CHECK_HTTPS)?->label)->toBe('Homepage (HTTPS)');
    expect($monitors->firstWhere('check_type', SiteUptimeMonitor::CHECK_HTTP)?->label)->toBe('Homepage (HTTP)');
    expect($monitors->every(fn (SiteUptimeMonitor $monitor): bool => $monitor->probe_region === 'us-east'))->toBeTrue();
    expect(SiteUptimeMonitor::query()->where('site_id', $site->id)->where('label', 'Homepage check')->exists())->toBeFalse();
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
