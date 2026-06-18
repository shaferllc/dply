<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ScaffoldJourneyTest;

use App\Modules\Scaffold\Jobs\RunLaravelScaffoldJob;
use App\Modules\Scaffold\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Sites\ScaffoldJourney;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use App\Modules\Scaffold\Services\ScaffoldStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

/**
 * Bind a stub PlaceholderDnsManager into the container so Livewire's
 * injection on retry() finds something safe — we don't want any
 * real DNS-API or nip.io HTTP calls firing from these tests.
 */
function stubPlaceholderDns(): PlaceholderDnsManager
{
    $mock = Mockery::mock(PlaceholderDnsManager::class);
    $mock->shouldReceive('release')->andReturnNull();
    $mock->shouldReceive('assign')->andReturn([
        'hostname' => 'stub.test', 'zone' => null, 'record_id' => null, 'source' => 'nip.io',
    ]);
    app()->instance(PlaceholderDnsManager::class, $mock);

    return $mock;
}
function makeSite(string $status, array $scaffoldMeta = [], string $userRole = 'admin'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $userRole]);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $status,
        'meta' => ['scaffold' => array_merge([
            'framework' => 'wordpress',
            'admin_email' => 'admin@example.com',
            'steps' => [
                ScaffoldStep::pending('prereqs', 'Verify prerequisites'),
                ScaffoldStep::pending('db_create', 'Create database'),
            ],
        ], $scaffoldMeta)],
    ]);

    return [$user, $server, $site];
}
test('running pipeline renders step list', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLDING);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Verify prerequisites')
        ->assertSee('Create database')
        ->assertSee('Auto-refreshing');
});
test('failed pipeline shows retry button when under attempt cap', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'attempt_count' => 1,
        'steps' => [
            ['key' => 'prereqs', 'label' => 'Verify prerequisites', 'state' => ScaffoldStep::STATE_FAILED, 'error' => 'wp-cli unavailable'],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Install failed')
        ->assertSee('Retry install')
        ->assertSee('wp-cli unavailable');
});
test('failed pipeline after three attempts offers delete only', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'attempt_count' => 3,
        'steps' => [
            ['key' => 'wp_install', 'label' => 'wp install', 'state' => ScaffoldStep::STATE_FAILED, 'error' => 'oops'],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->assertDontSee('Retry install')
        ->assertSee('Delete site and start fresh');
});
test('retry dispatches pipeline and resets state', function () {
    Bus::fake();
    stubPlaceholderDns();
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'attempt_count' => 1,
        'admin_password' => encrypt('old-password'),
        'steps' => [
            ['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->call('retryScaffold');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLDING);
    expect($site->meta['scaffold']['attempt_count'])->toBe(2);
    expect($site->meta['scaffold']['steps'])->toBe([]);
    $this->assertArrayNotHasKey('admin_password', $site->meta['scaffold']);

    Bus::assertDispatched(RunWordPressScaffoldJob::class,
        fn ($job) => $job->siteId === $site->id);
});
test('retry dispatches laravel job for laravel framework', function () {
    Bus::fake();
    stubPlaceholderDns();
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'framework' => 'laravel',
        'attempt_count' => 1,
        'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED]],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->call('retryScaffold');

    Bus::assertDispatched(RunLaravelScaffoldJob::class);
});
test('retry releases prior placeholder and drops site domain row', function () {
    Bus::fake();

    // Asserting mock — release must be called exactly once with this Site
    // before the new pipeline job is dispatched.
    $dns = Mockery::mock(PlaceholderDnsManager::class);
    $dns->shouldReceive('release')->once();
    $dns->shouldReceive('assign')->andReturn([
        'hostname' => 'unused.test', 'zone' => null, 'record_id' => null, 'source' => 'nip.io',
    ]);
    app()->instance(PlaceholderDnsManager::class, $dns);

    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'framework' => 'wordpress',
        'attempt_count' => 1,
        'placeholder_dns' => ['hostname' => 'old-blog.198-51-100-1.nip.io', 'source' => 'nip.io'],
        'steps' => [['key' => 'wp_install', 'state' => ScaffoldStep::STATE_FAILED]],
    ]);

    // Pre-existing SiteDomain row from the failed first attempt.
    $site->domains()->create(['hostname' => 'old-blog.198-51-100-1.nip.io', 'is_primary' => true, 'www_redirect' => false]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->call('retryScaffold');

    $site->refresh();
    expect($site->domains()->where('hostname', 'old-blog.198-51-100-1.nip.io')->count())->toBe(0, 'Stale SiteDomain row from prior attempt must be deleted to free the unique hostname constraint');
    Bus::assertDispatched(RunWordPressScaffoldJob::class);
});
test('retry is a no op after attempt cap', function () {
    Bus::fake();
    stubPlaceholderDns();
    [$user, $server, $site] = makeSite(Site::STATUS_SCAFFOLD_FAILED, [
        'attempt_count' => 3,
        'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_FAILED]],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->call('retryScaffold');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);
    Bus::assertNotDispatched(RunWordPressScaffoldJob::class);
    Bus::assertNotDispatched(RunLaravelScaffoldJob::class);
});
test('completed state renders password reveal', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_PENDING, [
        'admin_password' => encrypt('hunter2!hunter2'),
        'steps' => [
            ['key' => 'prereqs', 'state' => ScaffoldStep::STATE_COMPLETED],
            ['key' => 'db_create', 'state' => ScaffoldStep::STATE_COMPLETED],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Install complete')
        ->assertSee('Reveal password')
        ->assertDontSee('hunter2')
        ->call('revealScaffoldPassword')
        ->assertSet('scaffoldPasswordRevealed', true)
        ->assertSee('hunter2');
});
test('member role cannot reveal password', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_PENDING, [
        'admin_password' => encrypt('not-for-members'),
        'steps' => [['key' => 'prereqs', 'state' => ScaffoldStep::STATE_COMPLETED]],
    ], userRole: 'member');

    Livewire::actingAs($user)
        ->test(ScaffoldJourney::class, ['server' => $server, 'site' => $site])
        ->call('revealScaffoldPassword')
        ->assertSet('scaffoldPasswordRevealed', false)
        ->assertHasErrors('reveal');
});
test('404s for non scaffolded site', function () {
    [$user, $server, $site] = makeSite(Site::STATUS_NGINX_ACTIVE);
    $site->meta = [];
    $site->save();

    $this->actingAs($user)
        ->get(route('sites.scaffold-journey', ['server' => $server, 'site' => $site->fresh()]))
        ->assertNotFound();
});
