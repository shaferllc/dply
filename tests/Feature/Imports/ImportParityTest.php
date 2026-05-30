<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\ImportParityTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest is redirected from import parity', function (): void {
    $this->get(route('imports.parity'))
        ->assertRedirect(route('login'));
});

test('import parity lists edge sites imported from external providers', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'server_id' => $server->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'import' => [
                    'source' => 'vercel',
                    'source_project_id' => 'prj_test',
                    'imported_at' => now()->toIso8601String(),
                ],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('imports.parity'))
        ->assertOk()
        ->assertSee('Import parity')
        ->assertSee('Vercel')
        ->assertSee($site->name, false);
});
