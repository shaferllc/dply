<?php

namespace Tests\Feature\TaskRunner\AuthorizedKeysSynchronizerTaskRunnerTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\ServerAuthorizedKeysHealthCheck;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use phpseclib3\Crypt\RSA;

uses(RefreshDatabase::class);

function validPrivateKey(): string
{
    return file_get_contents(base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem'));
}

/**
 * Pull the final authorized_keys body out of a captured runScript invocation.
 *
 * The synchronizer wraps writes in two layers: the outer script carries
 * `INNER_B64='OUTER_B64'`; decoding that yields the inner bash, which itself
 * has `BODY=$(echo 'INNER_B64' | base64 -d)`. The doubly-decoded inner is the
 * final authorized_keys content we want to assert against. Returns null when
 * the script doesn't match the expected shape (test should treat as no-match).
 */
function extractWrittenAuthorizedKeysBody(string $script): ?string
{
    if (! preg_match('#INNER_B64=\'([A-Za-z0-9+/=]+)\'#', $script, $m)) {
        return null;
    }
    $innerScript = (string) base64_decode($m[1], true);
    if (! preg_match('#BODY=\\$\\(echo \'([A-Za-z0-9+/=]+)\' \\| base64 -d\\)#', $innerScript, $m2)) {
        return null;
    }

    return (string) base64_decode($m2[1], true);
}

/**
 * True when the decoded authorized_keys body for the captured runScript call
 * contains every expected key line and the inner DPLY_AUTH_EXIT sentinel is
 * present. Use inside a `withArgs(...)` closure paired with a `&$matched`
 * flag — Mockery's "last matching expectation wins" rule makes this more
 * reliable than declaring overlapping expectations.
 *
 * @param  list<string>  $expectedKeys  exact public-key lines that must each appear
 */
function authorizedKeysScriptMatches(string $script, array $expectedKeys): bool
{
    if (! preg_match('#INNER_B64=\'([A-Za-z0-9+/=]+)\'#', $script, $m)) {
        return false;
    }
    $innerScript = (string) base64_decode($m[1], true);
    if (! str_contains($innerScript, 'DPLY_AUTH_EXIT')) {
        return false;
    }
    $body = extractWrittenAuthorizedKeysBody($script);
    if ($body === null) {
        return false;
    }
    foreach ($expectedKeys as $key) {
        if (! str_contains($body, $key)) {
            return false;
        }
    }

    return true;
}

test('sync writes file from panel keys without merging remote file', function () {
    config()->set('server_ssh_keys.use_root_ssh', false);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.50',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

    ServerAuthorizedKey::create([
        'server_id' => $server->id,
        'public_key' => $panelKey,
        'name' => 'test',
    ]);

    // The synchronizer bundles panel keys + the server's own operational/recovery
    // keys into a single sorted body before base64-encoding (see
    // ServerAuthorizedKeysSynchronizer::desiredAuthorizedKeyLines). The script
    // double-base64-encodes: outer `INNER_B64='OUTER_B64'` then the decoded inner
    // bash contains `BODY=$(echo 'INNER_B64' | base64 -d)`. Walk both layers and
    // assert the panel key is in the final decoded body.
    // Synchronizer can call runScript once per resolved target user. We accept every
    // call and use a captured flag to assert at least one call carried the panel key
    // in the script body. This avoids fighting Mockery's "last matching expectation
    // wins" rule when we'd otherwise need two overlapping shouldReceive declarations.
    $matched = false;
    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->atLeast()->once()
        ->withArgs(function ($srv, $name, $script) use ($server, $panelKey, &$matched) {
            if (
                $srv->is($server)
                && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                && authorizedKeysScriptMatches($script, [$panelKey])
            ) {
                $matched = true;
            }

            return true;
        })
        ->andReturn(ProcessOutput::make('ok\nDPLY_AUTH_EXIT:0')->setExitCode(0));

    $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
        $mock->shouldReceive('record')->andReturnNull();
    });
    $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    Event::fake();

    $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
    $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

    $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    expect($matched)->toBeTrue('Expected runScript to be called at least once with the panel-key write script.');
});

test('sync with no keys still writes provisioned key for ssh user', function () {
    config()->set('server_ssh_keys.use_root_ssh', false);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.50',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $expectedProvisionedKey = $server->openSshPublicKeyFromPrivate();

    $matched = false;
    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->atLeast()->once()
        ->withArgs(function ($srv, $name, $script) use ($server, $expectedProvisionedKey, &$matched) {
            if (
                $srv->is($server)
                && $name === 'Write authorized_keys ('.$server->ssh_user.')'
                && authorizedKeysScriptMatches($script, [$expectedProvisionedKey])
                && str_contains($script, 'base64')
            ) {
                $matched = true;
            }

            return true;
        })
        ->andReturn(ProcessOutput::make('ok\nDPLY_AUTH_EXIT:0')->setExitCode(0));

    $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
        $mock->shouldReceive('record')->andReturnNull();
    });
    $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    Event::fake();

    $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
    $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

    $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    expect($matched)->toBeTrue('Expected runScript to write the provisioned key for the SSH user.');
});

test('sync preserves server provisioned key for login user', function () {
    config()->set('server_ssh_keys.use_root_ssh', false);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.50',
        'ssh_user' => 'dply',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

    ServerAuthorizedKey::create([
        'server_id' => $server->id,
        'public_key' => $panelKey,
        'name' => 'test',
    ]);

    $expectedProvisionedKey = $server->openSshPublicKeyFromPrivate();
    $loginMatched = false;
    $observedTargetNames = [];
    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->atLeast()->once()
        ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server, $panelKey, $expectedProvisionedKey, &$loginMatched, &$observedTargetNames) {
            if (! $srv->is($server) || ! is_string($script) || $timeout !== 60 || $asRoot !== false) {
                return true;
            }
            $observedTargetNames[] = $name;
            if (
                $name === 'Write authorized_keys ('.$server->ssh_user.')'
                && authorizedKeysScriptMatches($script, [$panelKey, $expectedProvisionedKey])
            ) {
                $loginMatched = true;
            }

            return true;
        })
        ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

    $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
        $mock->shouldReceive('record')->andReturnNull();
    });
    $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    Event::fake();

    $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
    $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

    $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    expect($loginMatched)->toBeTrue('Expected runScript to write panel + provisioned keys for the login user.');

    // The synchronizer also writes to additional targets (root, deploy users with
    // prior rows, the connection user). Verify the login target name appeared at
    // least once; richer assertions on the root path live in dedicated tests below
    // — checking the script body for sudo here is brittle because the wrapper
    // double-base64s the inner script.
    expect($observedTargetNames)->toContain('Write authorized_keys ('.$server->ssh_user.')');
});

test('sync retries with deploy user when root ssh fails', function () {
    config()->set('server_ssh_keys.use_root_ssh', true);
    config()->set('server_ssh_keys.fallback_to_deploy_user_ssh', true);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.50',
        'ssh_user' => 'dply',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->once()
        ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
            return $srv->is($server)
                && $name === 'Write authorized_keys (root)'
                && is_string($script)
                && $timeout === 60
                && $asRoot === true;
        })
        ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));
    $remote->shouldReceive('runScript')
        ->once()
        ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
            return $srv->is($server)
                && $name === 'Write authorized_keys (dply)'
                && is_string($script)
                && $timeout === 60
                && $asRoot === true;
        })
        ->andThrow(new \RuntimeException('root ssh failed'));
    $remote->shouldReceive('runScript')
        ->once()
        ->withArgs(function ($srv, $name, $script, $timeout, $asRoot) use ($server) {
            return $srv->is($server)
                && $name === 'Write authorized_keys (dply)'
                && is_string($script)
                && $timeout === 60
                && $asRoot === false;
        })
        ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

    $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
        $mock->shouldReceive('record')->andReturnNull();
    });
    $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    Event::fake();

    $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
    $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

    $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
});

test('sync preserves hidden root recovery key separately from operational login key', function () {
    config()->set('server_ssh_keys.use_root_ssh', false);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $recoveryKey = RSA::createKey(2048)->toString('OpenSSH');
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.50',
        'ssh_user' => 'dply',
        'ssh_private_key' => $recoveryKey,
        'ssh_recovery_private_key' => $recoveryKey,
        'ssh_operational_private_key' => validPrivateKey(),
    ]);

    $panelKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHRlc3QgdGVzdCB0ZXN0';

    ServerAuthorizedKey::create([
        'server_id' => $server->id,
        'public_key' => $panelKey,
        'name' => 'test',
    ]);

    $operationalKey = $server->openSshPublicKeyFromOperationalPrivate();
    $loginMatched = false;
    $observedTargetNames = [];
    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->atLeast()->once()
        ->withArgs(function ($srv, $name, $script) use ($server, $panelKey, $operationalKey, &$loginMatched, &$observedTargetNames) {
            if (! $srv->is($server)) {
                return true;
            }
            $observedTargetNames[] = $name;
            if (
                $name === 'Write authorized_keys (dply)'
                && authorizedKeysScriptMatches($script, [$panelKey, $operationalKey])
            ) {
                $loginMatched = true;
            }

            return true;
        })
        ->andReturn(ProcessOutput::make('ok'."\n".'DPLY_AUTH_EXIT:0')->setExitCode(0));

    $this->app->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->mock(ServerAuthorizedKeysAuditLogger::class, function ($mock) {
        $mock->shouldReceive('record')->andReturnNull();
    });
    $this->mock(ServerAuthorizedKeysHealthCheck::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    Event::fake();

    $sync = $this->app->make(ServerAuthorizedKeysSynchronizer::class);
    $out = $sync->sync($server->fresh(['authorizedKeys']), null, null);

    $this->assertStringContainsString('DPLY_AUTH_EXIT:0', $out);
    expect($loginMatched)->toBeTrue('Expected runScript to write panel + operational keys for the login user.');
    expect($observedTargetNames)->toContain('Write authorized_keys (dply)');
});
