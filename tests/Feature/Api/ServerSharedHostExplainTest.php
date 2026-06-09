<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

test('shared host explain api returns fairness advisor briefing', function (): void {
    Feature::define('workspace.shared_host', fn (): bool => true);
    Feature::flushCache();

    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    Site::factory()->count(2)->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'shared-host-api', null, ['servers.read']);

    $this->withToken($plain)
        ->getJson("/api/v1/servers/{$server->id}/shared-host/explain")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'server_id',
                'overall',
                'site_count',
                'summary',
                'severity',
                'recommendations',
                'briefing',
                'radar_url',
            ],
        ])
        ->assertJsonPath('data.site_count', 2);
});
