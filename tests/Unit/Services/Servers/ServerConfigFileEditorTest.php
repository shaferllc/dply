<?php

declare(strict_types=1);

use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\Servers\RemoteServerConfigService;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerConfigFileEditor;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('server config file editor records deduped revisions per path stream', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $editor = app(ServerConfigFileEditor::class);
    $path = '/etc/ssh/sshd_config';

    $editor->ensureBaseline($server, $path, "Port 22\n", $user);
    $editor->recordWrite($server, $path, "Port 2222\n", $user);
    $editor->recordWrite($server, $path, "Port 2222\n", $user);

    expect(ConfigRevision::query()->count())->toBe(2);
});

test('server config file diff renderer compares file contents', function (): void {
    $registry = app(ConfigRevisionDiffRegistry::class);
    $renderer = $registry->rendererFor(ServerConfigFileEditor::KIND);

    $diff = $renderer->render(
        ['path' => '/etc/ssh/sshd_config', 'content' => "Port 22\n"],
        ['path' => '/etc/ssh/sshd_config', 'content' => "Port 2222\n"],
    );

    expect($diff)->toContain('-Port 22')
        ->and($diff)->toContain('+Port 2222');
});

test('catalog resolves webserver engine from nginx path', function (): void {
    $catalog = app(ServerConfigFileCatalog::class);

    expect($catalog->webserverEngineForPath('/etc/nginx/nginx.conf'))->toBe('nginx')
        ->and($catalog->webserverEngineForPath('/etc/ssh/sshd_config'))->toBeNull();
});

test('catalog exposes autocomplete snippets by file type', function (): void {
    $catalog = app(ServerConfigFileCatalog::class);
    $items = $catalog->autocompleteForPath('/etc/nginx/nginx.conf');

    expect($items)->not->toBeEmpty()
        ->and($items[0])->toHaveKeys(['label', 'type', 'insert']);
});

test('remote server config service rejects disallowed paths', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $service = new RemoteServerConfigService($executor);
    $server = new Server;

    expect(fn () => $service->read($server, '/etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});

test('remote server config service resolves validation hook by prefix', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $service = new RemoteServerConfigService($executor);

    $hook = $service->validationHookFor('/etc/php/8.3/fpm/php.ini');

    expect($hook)->toBeArray()
        ->and($hook['validate'] ?? null)->toContain('php-fpm');
});
