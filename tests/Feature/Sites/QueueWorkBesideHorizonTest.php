<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\QueueWorkBesideHorizonTest;

use App\Livewire\Sites\WorkspaceQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @param  array<string, bool>  $commands  command => is_active */
function workersTab(array $commands, string $driver = 'redis'): Testable
{
    Bus::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);
    $site->putMeta('queue_observed', ['driver' => $driver]);

    foreach (array_keys($commands) as $i => $command) {
        SupervisorProgram::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'slug' => 'worker-'.$i,
            'program_type' => 'queue',
            'command' => $command,
            'directory' => '/home/dply/app',
            'user' => 'dply',
            'numprocs' => 1,
            'is_active' => $commands[$command],
        ]);
    }

    return Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site->fresh()])
        ->set('queue_workspace_tab', 'workers');
}

test('a Redis queue:work beside a running Horizon is flagged, even while stopped', function () {
    workersTab([
        'php artisan horizon' => true,
        "php artisan queue:work --queue='default' --sleep=3 --tries=3" => false,
    ])->assertSee('so this one is redundant');
});

test('a queue:work on a connection Horizon cannot drain is left alone', function () {
    workersTab([
        'php artisan horizon' => true,
        'php artisan queue:work database --queue=default' => true,
    ])->assertDontSee('so this one is redundant');
});

test('nothing is flagged when Horizon is not running', function () {
    workersTab([
        'php artisan horizon' => false,
        'php artisan queue:work --queue=default' => true,
    ])->assertDontSee('so this one is redundant');
});

test('adding a Redis worker to a site running Horizon warns before saving, without blocking', function () {
    workersTab(['php artisan horizon' => true])
        ->call('openCreate')
        ->assertSee('a queue:work on Redis would compete with it')
        // A non-Redis connection is the legitimate case: no warning.
        ->set('new_connection', 'database')
        ->assertDontSee('a queue:work on Redis would compete with it');
});
