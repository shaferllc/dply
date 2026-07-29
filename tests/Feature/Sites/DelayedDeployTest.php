<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\DelayedDeployTest;

use App\Actions\Sites\ScheduleSiteDeploy;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\ScheduledDeploy;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function vmSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
}

test('a due delayed deploy is dispatched and marked dispatched', function () {
    Queue::fake();
    $site = vmSite();
    $scheduled = ScheduledDeploy::create([
        'site_id' => $site->id,
        'run_at' => now()->subMinute(),
        'status' => ScheduledDeploy::STATUS_PENDING,
    ]);

    $this->artisan('dply:run-due-scheduled-deploys')->assertSuccessful();

    Queue::assertPushed(RunSiteDeploymentJob::class, 1);
    expect($scheduled->fresh()->status)->toBe(ScheduledDeploy::STATUS_DISPATCHED);
    expect($scheduled->fresh()->dispatched_at)->not->toBeNull();
});

test('a delayed deploy is not dispatched before its run_at', function () {
    Queue::fake();
    $site = vmSite();
    $scheduled = ScheduledDeploy::create([
        'site_id' => $site->id,
        'run_at' => now()->addHour(),
        'status' => ScheduledDeploy::STATUS_PENDING,
    ]);

    $this->artisan('dply:run-due-scheduled-deploys');

    Queue::assertNotPushed(RunSiteDeploymentJob::class);
    expect($scheduled->fresh()->status)->toBe(ScheduledDeploy::STATUS_PENDING);
});

test('a canceled delayed deploy is never dispatched', function () {
    Queue::fake();
    $site = vmSite();
    ScheduledDeploy::create([
        'site_id' => $site->id,
        'run_at' => now()->subMinute(),
        'status' => ScheduledDeploy::STATUS_CANCELED,
    ]);

    $this->artisan('dply:run-due-scheduled-deploys');

    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('a due deploy for a non-VM host is consumed but not dispatched', function () {
    Queue::fake();
    $site = vmSite();
    // Make the server a non-VM (functions) host so the VM pipeline guard skips it.
    $site->server->update(['meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS]]);
    $scheduled = ScheduledDeploy::create([
        'site_id' => $site->id,
        'run_at' => now()->subMinute(),
        'status' => ScheduledDeploy::STATUS_PENDING,
    ]);

    $this->artisan('dply:run-due-scheduled-deploys');

    // Marked dispatched so it doesn't re-evaluate every minute, but no job ran.
    expect($scheduled->fresh()->status)->toBe(ScheduledDeploy::STATUS_DISPATCHED);
    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('scheduling a deploy creates one pending row and replaces an earlier one', function () {
    $site = vmSite();
    $action = app(ScheduleSiteDeploy::class);

    $first = $action->schedule($site, '60', null);
    $second = $action->schedule($site, '15', null);

    expect($first->fresh()->status)->toBe(ScheduledDeploy::STATUS_CANCELED);
    expect($second->fresh()->status)->toBe(ScheduledDeploy::STATUS_PENDING);
    expect(ScheduledDeploy::query()->where('site_id', $site->id)->pending()->count())->toBe(1);
    expect($second->run_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
});

test('scheduling rejects a non-future time', function () {
    $site = vmSite();
    $action = app(ScheduleSiteDeploy::class);

    expect($action->schedule($site, '0', null))->toBeNull();
    expect($action->schedule($site, now()->subHour()->toDateTimeString(), null))->toBeNull();
    expect(ScheduledDeploy::query()->where('site_id', $site->id)->count())->toBe(0);
});

test('parseWhen handles minute presets, absolute datetimes, and junk', function () {
    $action = app(ScheduleSiteDeploy::class);

    expect($action->parseWhen('30')->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();
    expect($action->parseWhen(now()->addDay()->toDateTimeString())->isFuture())->toBeTrue();
    expect($action->parseWhen('not-a-time'))->toBeNull();
    expect($action->parseWhen(''))->toBeNull();
});

test('canceling clears the pending delayed deploy', function () {
    $site = vmSite();
    $action = app(ScheduleSiteDeploy::class);
    $action->schedule($site, '60', null);

    $action->cancelPending($site);

    expect($action->pendingFor($site))->toBeNull();
});

test('the due scope only returns pending rows at or past run_at', function () {
    $site = vmSite();
    ScheduledDeploy::create(['site_id' => $site->id, 'run_at' => now()->subMinute(), 'status' => 'pending']);
    ScheduledDeploy::create(['site_id' => $site->id, 'run_at' => now()->addHour(), 'status' => 'pending']);
    ScheduledDeploy::create(['site_id' => $site->id, 'run_at' => now()->subMinute(), 'status' => 'canceled']);

    expect(ScheduledDeploy::query()->due(now())->count())->toBe(1);
});
