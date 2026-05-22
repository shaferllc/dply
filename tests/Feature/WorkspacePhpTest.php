<?php

namespace Tests\Feature\WorkspacePhpTest;

use App\Livewire\Servers\WorkspacePhp;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpConfigValidationException;
use App\Services\Servers\ServerPhpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('authenticated user can open the php workspace route', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSeeInOrder(['aria-label="Server sections"', 'PHP'], false);
    $response->assertSeeInOrder(['<h1', 'PHP', '</h1>'], false);
});

test('php workspace shows provisioning not ready state', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_PENDING,
        'setup_status' => Server::SETUP_STATUS_PENDING,
        'ip_address' => null,
        'ssh_private_key' => null,
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('Provisioning and SSH must be ready before you can use this section.');
});

test('php workspace shows ssh unavailable state for ready server without ssh access', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => null,
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('SSH unavailable');
});

test('php workspace shows inventory never run state', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [],
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('No PHP inventory yet');
});

test('php workspace shows refresh failure state', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory_refresh' => [
                'status' => 'failed',
                'error' => 'apt cache lock timeout',
            ],
        ],
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('PHP inventory refresh failed');
    $response->assertSee('apt cache lock timeout');
});

test('php workspace shows refresh running state', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory_refresh' => [
                'status' => 'running',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('PHP inventory refresh running');
});

test('php workspace shows stale inventory warning after failed action', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
            'php_inventory_refresh' => [
                'status' => 'stale',
                'error' => 'Remote PHP state changed, but Dply could not save the refreshed snapshot.',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('PHP inventory may be stale');
    $response->assertSee('Remote PHP state changed, but Dply could not save the refreshed snapshot.');
});

test('php workspace shows unsupported environment state', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => false,
            ],
        ],
    ]);

    expect(Route::has('servers.php'))->toBeTrue('Expected [servers.php] route to exist.');

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('Unsupported environment');
});

test('php workspace can refresh inventory from livewire', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->andReturn([
            'summary' => [
                'supported_versions' => [],
                'installed_versions' => [],
                'installed_count' => 0,
                'is_supported_environment' => true,
                'cli_default' => null,
                'new_site_default' => null,
                'detected_default_version' => null,
            ],
            'version_rows' => [],
        ]);
    $manager->shouldReceive('refreshInventory')
        ->once()
        ->withArgs(fn (Server $refreshedServer) => $refreshedServer->is($server))
        ->andReturn([
            'status' => 'succeeded',
            'message' => 'PHP inventory refreshed.',
            'output' => "Supported environment: yes\nInstalled versions: 8.3\nDetected CLI default: 8.3",
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('refreshPhpInventory')
        ->assertDispatched('notify', message: 'PHP inventory refreshed.', type: 'success')
        ->assertSet('remote_output', "Supported environment: yes\nInstalled versions: 8.3\nDetected CLI default: 8.3");
});

test('php workspace renders version rows and package actions', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.3',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
        ],
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'php_version' => '8.3',
    ]);

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('PHP 8.4');
    $response->assertSee('PHP 8.3');
    $response->assertSee('CLI default');
    $response->assertSee('Default for new sites');
    $response->assertSee('Used by 1 site');
    $response->assertSee('Install');
    $response->assertSee('Installing…');
    $response->assertSee('Patch');
    $response->assertSee('Patching…');
    $response->assertSee('Set CLI default');
    $response->assertSee('Setting CLI default…');
    $response->assertSee('Set new-site default');
    $response->assertSee('Setting new-site default…');
    $response->assertSee('Uninstall');
    $response->assertSee('Uninstalling…');
    $response->assertSee('CLI ini');
    $response->assertSee('Opening CLI ini…');
    $response->assertSee('FPM ini');
    $response->assertSee('Opening FPM ini…');
    $response->assertSee('Pool config');
    $response->assertSee('Opening pool config…');
    $response->assertSeeHtml('wire:loading.attr="disabled"');
});

test('php workspace falls back to known default version before inventory refresh', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_version' => '8.3',
            'default_php_version' => '8.3',
        ],
    ]);

    $response = $this->actingAs($user)->get(route('servers.php', $server, false));

    $response->assertOk();
    $response->assertSee('PHP 8.3');
    $response->assertSee('Installed versions');
    $response->assertSee('CLI default');
    $response->assertDontSee('No PHP inventory yet');
});

test('php workspace can open a version config editor from livewire', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ],
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(2)
        ->andReturn([
            'summary' => [
                'supported_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3'],
                ],
                'installed_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'site_count' => 0],
                ],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.3',
                'new_site_default' => '8.3',
                'detected_default_version' => '8.3',
            ],
            'version_rows' => [
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'is_installed' => true, 'site_count' => 0],
            ],
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(ConfigRevisionRecorder::class)])->makePartial();
    $editor->shouldReceive('openTarget')
        ->once()
        ->withArgs(fn (Server $refreshedServer, string $version, string $target) => $refreshedServer->is($server) && $version === '8.3' && $target === 'cli_ini')
        ->andReturn([
            'version' => '8.3',
            'target' => 'cli_ini',
            'label' => 'CLI ini',
            'path' => '/etc/php/8.3/cli/php.ini',
            'content' => "memory_limit=512M\n",
            'reload_guidance' => 'Reload is not required for CLI ini changes, but new CLI processes will use the updated file.',
        ]);
    $this->app->instance(ServerPhpConfigEditor::class, $editor);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('openPhpConfigEditor', '8.3', 'cli_ini')
        ->assertSet('phpConfigEditorOpen', true)
        ->assertSet('phpConfigEditorTargetLabel', 'CLI ini')
        ->assertSet('phpConfigEditorPath', '/etc/php/8.3/cli/php.ini')
        ->assertSet('phpConfigEditorContent', "memory_limit=512M\n");
});

test('php workspace can save a version config edit from livewire', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ],
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(4)
        ->andReturn([
            'summary' => [
                'supported_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3'],
                ],
                'installed_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'site_count' => 0],
                ],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.3',
                'new_site_default' => '8.3',
                'detected_default_version' => '8.3',
            ],
            'version_rows' => [
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'is_installed' => true, 'site_count' => 0],
            ],
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(ConfigRevisionRecorder::class)])->makePartial();
    $editor->shouldReceive('openTarget')
        ->once()
        ->andReturn([
            'version' => '8.3',
            'target' => 'fpm_ini',
            'label' => 'FPM ini',
            'path' => '/etc/php/8.3/fpm/php.ini',
            'content' => "memory_limit=256M\n",
            'reload_guidance' => 'Reload PHP-FPM 8.3 after saving to apply these changes.',
        ]);
    $editor->shouldReceive('saveTarget')
        ->once()
        ->withArgs(fn (Server $refreshedServer, string $version, string $target, string $content, $user = null, $summary = null) => $refreshedServer->is($server) && $version === '8.3' && $target === 'fpm_ini' && $content === "memory_limit=512M\n")
        ->andReturn([
            'message' => 'FPM ini saved for PHP 8.3.',
            'reload_guidance' => 'Reload PHP-FPM 8.3 after saving to apply these changes.',
            'verification_output' => 'configuration file syntax is ok',
            'output' => "configuration file syntax is ok\n\nFPM ini saved and PHP-FPM 8.3 reloaded.",
        ]);
    $this->app->instance(ServerPhpConfigEditor::class, $editor);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('openPhpConfigEditor', '8.3', 'fpm_ini')
        ->set('phpConfigEditorContent', "memory_limit=512M\n")
        ->call('savePhpConfigEditor')
        ->assertDispatched('notify', message: 'FPM ini saved for PHP 8.3.', type: 'success')
        ->assertSet('phpConfigEditorReloadGuidance', 'Reload PHP-FPM 8.3 after saving to apply these changes.')
        ->assertSet('phpConfigEditorValidationOutput', 'configuration file syntax is ok')
        ->assertSet('remote_output', "configuration file syntax is ok\n\nFPM ini saved and PHP-FPM 8.3 reloaded.");
});

test('php workspace surfaces config validation failures without replacing the live file', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ],
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(4)
        ->andReturn([
            'summary' => [
                'supported_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3'],
                ],
                'installed_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'site_count' => 0],
                ],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.3',
                'new_site_default' => '8.3',
                'detected_default_version' => '8.3',
            ],
            'version_rows' => [
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'is_installed' => true, 'site_count' => 0],
            ],
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(ConfigRevisionRecorder::class)])->makePartial();
    $editor->shouldReceive('openTarget')
        ->once()
        ->andReturn([
            'version' => '8.3',
            'target' => 'cli_ini',
            'label' => 'CLI ini',
            'path' => '/etc/php/8.3/cli/php.ini',
            'content' => "memory_limit=256M\n",
            'reload_guidance' => 'Reload is not required for CLI ini changes, but new CLI processes will use the updated file.',
        ]);
    $editor->shouldReceive('saveTarget')
        ->once()
        ->andThrow(new ServerPhpConfigValidationException(
            'CLI ini validation failed. The live file was not replaced.',
            'PHP: syntax error on line 2'
        ));
    $this->app->instance(ServerPhpConfigEditor::class, $editor);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('openPhpConfigEditor', '8.3', 'cli_ini')
        ->set('phpConfigEditorContent', "memory_limit==512M\n")
        ->call('savePhpConfigEditor')
        ->assertDispatched('notify', message: 'CLI ini validation failed. The live file was not replaced.', type: 'error')
        ->assertSet('phpConfigEditorValidationOutput', 'PHP: syntax error on line 2')
        ->assertSet('remote_output', 'PHP: syntax error on line 2')
        ->assertSet('phpConfigEditorContent', "memory_limit==512M\n");
});

test('php workspace can run a package action from livewire', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(2)
        ->andReturn([
            'summary' => [
                'supported_versions' => [
                    ['id' => '8.4', 'label' => 'PHP 8.4'],
                    ['id' => '8.3', 'label' => 'PHP 8.3'],
                ],
                'installed_versions' => [
                    ['id' => '8.4', 'label' => 'PHP 8.4', 'is_supported' => true, 'site_count' => 0],
                ],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.4',
                'new_site_default' => '8.4',
                'detected_default_version' => '8.4',
            ],
            'version_rows' => [
                ['id' => '8.4', 'label' => 'PHP 8.4', 'is_supported' => true, 'site_count' => 0],
            ],
        ]);
    $manager->shouldReceive('applyPackageAction')
        ->once()
        ->withArgs(fn (Server $refreshedServer, string $action, string $version) => $refreshedServer->is($server) && $action === 'install' && $version === '8.4')
        ->andReturn([
            'status' => 'succeeded',
            'message' => 'PHP 8.4 installed.',
            'output' => "Installing packages...\n\nSupported environment: yes\nInstalled versions: 8.4\nDetected CLI default: 8.4",
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('runPhpPackageAction', 'install', '8.4')
        ->assertDispatched('notify', message: 'PHP 8.4 installed.', type: 'success')
        ->assertSet('remote_output', "Installing packages...\n\nSupported environment: yes\nInstalled versions: 8.4\nDetected CLI default: 8.4");
});

test('php workspace surfaces package action failures', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(2)
        ->andReturn([
            'summary' => [
                'supported_versions' => [],
                'installed_versions' => [],
                'installed_count' => 0,
                'is_supported_environment' => true,
                'cli_default' => null,
                'new_site_default' => null,
                'detected_default_version' => null,
            ],
            'version_rows' => [],
        ]);
    $manager->shouldReceive('applyPackageAction')
        ->once()
        ->andThrow(new \RuntimeException('PHP 8.3 is still used by 1 site.'));
    $this->app->instance(ServerPhpManager::class, $manager);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('runPhpPackageAction', 'uninstall', '8.3')
        ->assertDispatched('notify', message: 'PHP 8.3 is still used by 1 site.', type: 'error')
        ->assertSet('remote_error', 'PHP 8.3 is still used by 1 site.');
});

test('php workspace rejects package actions while another server mutation is running', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $lock = Cache::lock('server-php-package-action:'.$server->id, 30);
    expect($lock->get())->toBeTrue();

    try {
        Livewire::actingAs($user)
            ->test(WorkspacePhp::class, ['server' => $server])
            ->call('runPhpPackageAction', 'install', '8.4')
            ->assertDispatched('notify', message: 'Another PHP package action is already running for this server.', type: 'error');
    } finally {
        $lock->release();
    }
});

test('php workspace rejects config saves while another server mutation is running', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ],
    ]);

    $manager = Mockery::mock(ServerPhpManager::class);
    $manager->shouldReceive('workspaceData')
        ->times(4)
        ->andReturn([
            'summary' => [
                'supported_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3'],
                ],
                'installed_versions' => [
                    ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'site_count' => 0],
                ],
                'installed_count' => 1,
                'is_supported_environment' => true,
                'cli_default' => '8.3',
                'new_site_default' => '8.3',
                'detected_default_version' => '8.3',
            ],
            'version_rows' => [
                ['id' => '8.3', 'label' => 'PHP 8.3', 'is_supported' => true, 'is_installed' => true, 'site_count' => 0],
            ],
        ]);
    $this->app->instance(ServerPhpManager::class, $manager);

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(ConfigRevisionRecorder::class)])->makePartial();
    $editor->shouldReceive('openTarget')
        ->once()
        ->andReturn([
            'version' => '8.3',
            'target' => 'cli_ini',
            'label' => 'CLI ini',
            'path' => '/etc/php/8.3/cli/php.ini',
            'content' => "memory_limit=256M\n",
            'reload_guidance' => 'Reload is not required for CLI ini changes, but new CLI processes will use the updated file.',
        ]);
    $editor->shouldReceive('saveTarget')
        ->once()
        ->andThrow(new \RuntimeException('Another PHP server mutation is already running for this server.'));
    $this->app->instance(ServerPhpConfigEditor::class, $editor);

    Livewire::actingAs($user)
        ->test(WorkspacePhp::class, ['server' => $server])
        ->call('openPhpConfigEditor', '8.3', 'cli_ini')
        ->set('phpConfigEditorContent', "memory_limit=512M\n")
        ->call('savePhpConfigEditor')
        ->assertDispatched('notify', message: 'Another PHP server mutation is already running for this server.', type: 'error');
});
