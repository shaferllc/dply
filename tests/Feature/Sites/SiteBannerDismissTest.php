<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteBannerDismissTest;

use App\Livewire\Sites\Database;
use App\Livewire\Sites\WebserverConfig;
use App\Livewire\Sites\WorkspaceQueue;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// These pages render the static console banner, whose Dismiss button calls
// dismissConsoleActionRun — a method they did not have, so the click was a 500.
test('Dismiss on a site console banner is handled by the page that renders it', function (string $component) {
    Bus::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);
    // The webserver config page renders the vhost, which needs a hostname.
    $site->domains()->create(['hostname' => 'app.example.com', 'is_primary' => true]);

    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'site.test',
        'status' => ConsoleAction::STATUS_FAILED,
        'finished_at' => now(),
        'label' => 'Build',
    ]);

    Livewire::actingAs($user)
        ->test($component, ['server' => $server, 'site' => $site])
        ->call('dismissConsoleActionRun', (string) $run->id)
        ->assertOk();

    expect($run->fresh()->dismissed_at)->not->toBeNull();
})->with([
    'queue' => WorkspaceQueue::class,
    'database' => Database::class,
    'webserver config' => WebserverConfig::class,
]);

test('a page can only dismiss its own site’s runs', function () {
    Bus::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);
    $other = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);

    $foreign = ConsoleAction::query()->create([
        'subject_type' => $other->getMorphClass(),
        'subject_id' => $other->id,
        'kind' => 'site.test',
        'status' => ConsoleAction::STATUS_FAILED,
        'finished_at' => now(),
        'label' => 'Build',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('dismissConsoleActionRun', (string) $foreign->id);

    expect($foreign->fresh()->dismissed_at)->toBeNull();
});
