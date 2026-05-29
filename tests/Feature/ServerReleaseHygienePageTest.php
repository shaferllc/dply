<?php

declare(strict_types=1);

namespace Tests\Feature\ServerReleaseHygienePageTest;

use App\Livewire\Servers\WorkspaceReleaseHygiene;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerSystemLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.release_hygiene', 'workspace.run');

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

function hygieneUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => FAKE_SSH_KEY,
        'meta' => [
            'host_kind' => 'vm',
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'sites' => [],
                'system' => [],
            ],
        ],
    ]);

    return [$user, $server];
}

test('server release hygiene page is hidden when feature and preview are off', function (): void {
    Feature::define('workspace.release_hygiene', fn (): bool => false);
    Feature::define('workspace.release_hygiene_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = hygieneUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertNotFound();
});

test('server release hygiene page renders coming soon when feature off but preview on', function (): void {
    Feature::define('workspace.release_hygiene', fn (): bool => false);
    Feature::define('workspace.release_hygiene_preview', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = hygieneUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Release hygiene'));
});

test('server release hygiene page renders rollup', function (): void {
    [$user, $server] = hygieneUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertOk()
        ->assertSee(__('Release hygiene'))
        ->assertSee(__('Scan disk'))
        ->assertSee(__('Prune saved command'));
});

test('org owner can install prune saved command once', function (): void {
    [$user, $server] = hygieneUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygiene::class, ['server' => $server])
        ->call('installPruneSavedCommand')
        ->assertHasNoErrors();

    expect(ServerRecipe::query()->where('server_id', $server->id)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygiene::class, ['server' => $server->fresh()])
        ->call('installPruneSavedCommand')
        ->assertHasNoErrors();

    expect(ServerRecipe::query()->where('server_id', $server->id)->count())->toBe(1);
});

test('non vm host returns 404', function (): void {
    [$user, $server] = hygieneUserWithServer();
    $server->update(['meta' => ['host_kind' => 'kubernetes']]);

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server->fresh()))
        ->assertNotFound();
});

test('hygiene page shows view log buttons when scan has log paths', function (): void {
    [$user, $server] = hygieneUserWithServer();
    $server->update([
        'status' => 'ready',
        'meta' => array_merge(is_array($server->meta) ? $server->meta : [], [
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'sites' => [[
                    'slug' => 'app',
                    'laravel_log_bytes' => 4096,
                    'laravel_log_path' => '/var/www/app/shared/storage/logs/laravel.log',
                    'failed_jobs' => 0,
                ]],
                'system' => [
                    'logfiles' => [[
                        'path' => '/var/log/nginx/error.log',
                        'bytes' => 1024,
                    ]],
                ],
            ],
        ]),
    ]);

    Site::factory()->create([
        'organization_id' => $server->organization_id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'slug' => 'app',
    ]);

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server->fresh()))
        ->assertOk()
        ->assertSee(__('View'))
        ->assertSee('/var/log/nginx/error.log');
});

test('org owner can open hygiene log modal with mocked tail', function (): void {
    [$user, $server] = hygieneUserWithServer();
    $logPath = '/var/log/nginx/error.log';
    $server->update([
        'status' => 'ready',
        'meta' => array_merge(is_array($server->meta) ? $server->meta : [], [
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'sites' => [],
                'system' => [
                    'logfiles' => [[
                        'path' => $logPath,
                        'bytes' => 1024,
                    ]],
                ],
            ],
        ]),
    ]);

    $this->mock(ServerSystemLogReader::class, function ($mock) use ($logPath): void {
        $mock->shouldReceive('tailAllowlistedFile')
            ->once()
            ->withArgs(fn ($server, string $path): bool => $path === $logPath)
            ->andReturn(['output' => "[2026-05-27] test error line\n", 'error' => null]);
    });

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygiene::class, ['server' => $server->fresh()])
        ->call('viewHygieneLog', $logPath, $logPath)
        ->assertSet('showHygieneLogModal', true)
        ->assertSet('hygieneLogOutput', "[2026-05-27] test error line\n");
});
