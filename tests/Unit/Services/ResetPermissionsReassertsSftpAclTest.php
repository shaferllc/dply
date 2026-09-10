<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ResetPermissionsReassertsSftpAclTest;

use App\Models\Server;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;
use App\Services\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * "Reset file permissions" runs `find … -exec chmod`, which rewrites mode bits
 * and squashes the POSIX ACL mask — so every FTP account on the site silently
 * loses access. This is not hypothetical: it is a button in the UI, and the two
 * features are wired together only by the re-assert this test guards.
 *
 * Asserting on the generated script (rather than a real filesystem) is the point:
 * the regression is "someone edits resetSiteFilePermissions and drops the loop".
 */
test('resetSiteFilePermissions re-asserts ACL grants for every FTP account on the site', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => 'test-key',
        'ssh_user' => 'dply',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'repository_path' => '/home/dply/example.com',
        'document_root' => '/home/dply/example.com/public',
    ]);

    SftpAccount::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);
    SftpAccount::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'username' => 'agency',
        'home_path' => '/home/agency',
    ]);

    $captured = '';

    $ssh = Mockery::mock(SshConnection::class);
    $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$captured): string {
        $captured = $cmd;

        return "ok\nDPLY_EXIT:0";
    });

    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $runner->shouldReceive('run')->andReturnUsing(fn ($srv, $cb) => $cb($ssh, 'root'));

    (new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy))
        ->resetSiteFilePermissions($site->fresh());

    // The chmod sweep still happens...
    expect($captured)->toContain('find "$ROOT" -type d -exec chmod 755');

    // ...and both accounts are re-granted in the same run, after it.
    expect($captured)
        ->toContain("setfacl -R -m u:'designer':rwX '/home/dply/example.com'")
        ->toContain("setfacl -R -m u:'agency':rwX '/home/dply/example.com'");

    $chmodAt = strpos($captured, 'find "$ROOT" -type d -exec chmod 755');
    $aclAt = strpos($captured, "setfacl -R -m u:'designer'");
    expect($aclAt)->toBeGreaterThan($chmodAt);
});

/** No FTP accounts means no setfacl noise appended to the reset script. */
test('resetSiteFilePermissions emits no ACL work when the site has no FTP accounts', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'test-key', 'ssh_user' => 'dply']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'repository_path' => '/home/dply/plain.com',
        'document_root' => '/home/dply/plain.com/public',
    ]);

    $captured = '';
    $ssh = Mockery::mock(SshConnection::class);
    $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$captured): string {
        $captured = $cmd;

        return "ok\nDPLY_EXIT:0";
    });
    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $runner->shouldReceive('run')->andReturnUsing(fn ($srv, $cb) => $cb($ssh, 'root'));

    (new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy))
        ->resetSiteFilePermissions($site->fresh());

    expect($captured)->not->toContain('setfacl');
});
