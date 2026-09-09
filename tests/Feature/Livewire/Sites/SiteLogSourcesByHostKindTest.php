<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\SiteLogSourcesByHostKindTest;

use App\Livewire\Sites\Logs;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

/**
 * @param  Server::HOST_KIND_*  $hostKind
 * @return array{0: User, 1: Server, 2: Site}
 */
function siteOnHostKind(string $hostKind): array
{
    $user = ownerWithOrganization();
    $org = $user->currentOrganization();

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => $hostKind],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

/** @return list<string> */
function logSourceTypesFor(string $hostKind): array
{
    [$user, $server, $site] = siteOnHostKind($hostKind);

    $sources = Livewire::actingAs($user)
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->instance()
        ->availableLogSources();

    return array_values(array_map(
        static fn (array $source): string => (string) $source['type'],
        $sources,
    ));
}

test('a vm site still offers its nginx log files', function () {
    $types = logSourceTypesFor(Server::HOST_KIND_VM);

    expect($types)->toContain('dply_site');
    expect($types)->toContain('file');
});

test('a serverless function site offers no file log sources', function () {
    // A FaaS namespace has no disk and no SSH — offering /var/log/nginx paths
    // only ever renders "SSH blocked / Unavailable" rows for files that cannot
    // exist. The function's real logs live in the Serverless Logs workspace.
    $types = logSourceTypesFor(Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS);

    expect($types)->toBe(['dply_site']);
    expect($types)->not->toContain('file');
});

test('aws lambda, managed container and edge sites also offer no file log sources', function (string $hostKind) {
    expect(logSourceTypesFor($hostKind))->toBe(['dply_site']);
})->with([
    Server::HOST_KIND_AWS_LAMBDA,
    Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM,
    Server::HOST_KIND_AWS_APP_RUNNER,
    Server::HOST_KIND_DPLY_CLOUD,
    Server::HOST_KIND_DPLY_EDGE,
]);
