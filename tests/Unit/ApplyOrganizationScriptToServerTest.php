<?php

declare(strict_types=1);

namespace Tests\Unit\ApplyOrganizationScriptToServerTest;

use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use App\Modules\Marketplace\Scripts\ApplyOrganizationScriptToServer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('apply creates server recipe from organization script', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $script = Script::factory()->forOrganization($org, $user)->create([
        'name' => 'Disk cleanup',
        'content' => "#!/bin/bash\ndf -h\n",
    ]);

    $recipe = app(ApplyOrganizationScriptToServer::class)->apply(
        $script,
        $server->fresh(),
        $user,
        $org,
    );

    expect($recipe)->toBeInstanceOf(ServerRecipe::class)
        ->and($recipe->name)->toBe('Disk cleanup')
        ->and($recipe->script)->toBe("#!/bin/bash\ndf -h\n")
        ->and($recipe->server_id)->toBe($server->id);

    $this->assertDatabaseCount('server_recipes', 1);
});

test('apply updates existing recipe with same name', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $existing = ServerRecipe::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Disk cleanup',
        'script' => 'old',
    ]);

    $script = Script::factory()->forOrganization($org, $user)->create([
        'name' => 'Disk cleanup',
        'content' => 'new script body',
    ]);

    $recipe = app(ApplyOrganizationScriptToServer::class)->apply(
        $script,
        $server->fresh(),
        $user,
        $org,
    );

    expect($recipe->id)->toBe($existing->id)
        ->and($recipe->fresh()->script)->toBe('new script body');

    $this->assertDatabaseCount('server_recipes', 1);
});
