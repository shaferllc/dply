<?php

declare(strict_types=1);

use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\ConfigRevisions\Services\Diff\ConfigRevisionDiffRegistry;
use App\Services\Servers\ConfigFileDescriptionResolver;
use App\Services\Servers\RemoteServerConfigService;
use App\Services\Servers\RemoteWebserverConfigService;
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

test('catalog discovers remote files in a single ssh batch', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function (Server $server, string $taskName, string $script, ?int $timeout): bool {
            return $taskName === 'server-config:catalog-batch'
                && str_contains($script, '__DPLY_PROBE_0__')
                && str_contains($script, '/etc/nginx/nginx.conf')
                && str_contains($script, '/etc/php/*/fpm/php.ini')
                && $timeout === 30;
        })
        ->andReturn(new ProcessOutput(
            implode("\n", [
                '__DPLY_PROBE_0__',
                '/etc/nginx/nginx.conf|1446|1701383567',
                '/etc/nginx/sites-available/default|2412|1701383567',
                '__DPLY_PROBE_6__',
                '/etc/php/8.4/fpm/php.ini|69369|1778773756',
            ]),
            0,
        ));

    $catalog = new ServerConfigFileCatalog(
        app(RemoteWebserverConfigService::class),
        $executor,
        app(ConfigFileDescriptionResolver::class),
    );
    $server = Server::factory()->make();

    $groups = $catalog->groupedFiles($server);

    expect($groups)->toHaveKey('webserver')
        ->and($groups['webserver']['files'][0]['path'])->toBe('/etc/nginx/nginx.conf')
        ->and(collect($groups['webserver']['files'])->pluck('path'))->toContain('/etc/nginx/sites-available/default')
        ->and(collect($groups['php']['files'] ?? [])->pluck('path'))->toContain('/etc/php/8.4/fpm/php.ini');
});

test('catalog only probes installed webserver engines when stack is known', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function (Server $server, string $taskName, string $script): bool {
            return $taskName === 'server-config:catalog-batch'
                && str_contains($script, '/etc/nginx/nginx.conf')
                && ! str_contains($script, '/etc/apache2/apache2.conf');
        })
        ->andReturn(new ProcessOutput("__DPLY_PROBE_0__\n/etc/nginx/nginx.conf|100|1\n", 0));

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $task = Task::query()->create([
        'name' => 'stack',
        'action' => 'provision',
        'script' => 'x',
        'timeout' => 60,
        'user' => 'root',
        'status' => TaskStatus::Finished,
        'output' => '',
        'server_id' => $server->id,
        'created_by' => $user->id,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $run = ServerProvisionRun::query()->create([
        'server_id' => $server->id,
        'task_id' => $task->id,
        'attempt' => 1,
        'status' => 'completed',
        'summary' => 'done',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $run->artifacts()->create([
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack',
        'metadata' => [
            'webserver' => 'nginx',
            'php_version' => '8.4',
            'database' => 'none',
            'cache_service' => 'none',
            'expected_services' => ['nginx', 'php-fpm'],
        ],
        'content' => '{}',
    ]);

    $catalog = new ServerConfigFileCatalog(
        app(RemoteWebserverConfigService::class),
        $executor,
        app(ConfigFileDescriptionResolver::class),
    );
    $groups = $catalog->groupedFiles($server->fresh());

    expect($groups)->toHaveKey('webserver')
        ->and(collect($groups['webserver']['files'])->pluck('engine')->unique()->all())->toBe(['nginx']);
});

test('catalog probes scoped webserver engine even when stack lists another', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function (Server $server, string $taskName, string $script): bool {
            return $taskName === 'server-config:catalog-batch'
                && str_contains($script, '/etc/caddy/Caddyfile')
                && ! str_contains($script, '/etc/nginx/nginx.conf');
        })
        ->andReturn(new ProcessOutput(
            implode("\n", [
                '__DPLY_PROBE_0__',
                '/etc/caddy/Caddyfile|512|1',
                '/etc/caddy/sites-enabled/demo.caddy|900|2',
            ]),
            0,
        ));

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $task = Task::query()->create([
        'name' => 'stack',
        'action' => 'provision',
        'script' => 'x',
        'timeout' => 60,
        'user' => 'root',
        'status' => TaskStatus::Finished,
        'output' => '',
        'server_id' => $server->id,
        'created_by' => $user->id,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $run = ServerProvisionRun::query()->create([
        'server_id' => $server->id,
        'task_id' => $task->id,
        'attempt' => 1,
        'status' => 'completed',
        'summary' => 'done',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $run->artifacts()->create([
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack',
        'metadata' => [
            'webserver' => 'nginx',
            'expected_services' => ['nginx', 'php-fpm'],
        ],
        'content' => '{}',
    ]);

    $catalog = new ServerConfigFileCatalog(
        app(RemoteWebserverConfigService::class),
        $executor,
        app(ConfigFileDescriptionResolver::class),
    );
    $groups = $catalog->groupedFiles($server->fresh(), 'caddy');

    expect($groups)->toHaveKey('webserver')
        ->and(collect($groups['webserver']['files'])->pluck('path')->all())
        ->toContain('/etc/caddy/Caddyfile', '/etc/caddy/sites-enabled/demo.caddy');
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

test('remote server config service resolves mysql validation hook', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $service = new RemoteServerConfigService($executor);

    $hook = $service->validationHookFor('/etc/mysql/my.cnf');

    expect($hook)->toBeArray()
        ->and($hook['validate'] ?? null)->toContain('mysqld');
});

test('remote server config service maps nginx paths to webserver engine hook', function (): void {
    $executor = Mockery::mock(ServerManageSshExecutor::class);
    $service = new RemoteServerConfigService($executor);

    expect($service->validationHookFor('/etc/nginx/sites-available/default'))
        ->toMatchArray(['engine' => 'nginx']);
});
