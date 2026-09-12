<?php

namespace Tests\Unit\SiteRuntimePhpLabelsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Servers\SchedulerRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('runtime php process section title matches detected framework', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $laravel = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);
    $this->assertStringContainsString('Laravel', $laravel->runtimePhpProcessSectionTitle());

    $symfony = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'symfony', 'language' => 'php'],
            ],
        ],
    ]);
    $this->assertStringContainsString('Symfony', $symfony->runtimePhpProcessSectionTitle());
    $this->assertStringNotContainsString('Laravel', $symfony->runtimePhpProcessSectionTitle());

    $generic = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'php_generic', 'language' => 'php'],
            ],
        ],
    ]);
    expect($generic->runtimePhpProcessSectionTitle())->toBe(__('PHP process'));

    $wordpress = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'wordpress', 'language' => 'php'],
            ],
        ],
    ]);
    expect($wordpress->runtimePhpProcessSectionTitle())->toBe(__('PHP process'));
    $this->assertStringNotContainsString('WordPress', $wordpress->runtimePhpProcessSectionTitle());
});

test('the scheduler recipe is laravel only when laravel is detected', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $laravel = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);
    expect(SchedulerRecipe::for($laravel)?->name)->toBe('Laravel');

    $symfony = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'symfony', 'language' => 'php'],
            ],
        ],
    ]);
    // No recipe: the Schedule page asks for the command instead.
    expect(SchedulerRecipe::for($symfony))->toBeNull();
});
