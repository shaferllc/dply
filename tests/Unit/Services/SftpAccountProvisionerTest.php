<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SftpAccountProvisionerTest;

use App\Models\Server;
use App\Models\SftpAccount;
use App\Models\Site;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;
use App\Services\Servers\SftpAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function makeProvisioner(ServerSshConnectionRunner $runner): SftpAccountProvisioner
{
    return new SftpAccountProvisioner(
        $runner,
        new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy),
    );
}

function makeAccount(?string $repoPath = '/home/dply/example.com'): SftpAccount
{
    $server = Server::factory()->ready()->make(['ssh_private_key' => 'k', 'ssh_user' => 'dply']);
    $account = SftpAccount::factory()->make([
        'username' => 'designer',
        'home_path' => '/home/designer',
    ]);
    $account->setRelation('server', $server);

    if ($repoPath !== null) {
        $site = Site::factory()->make(['repository_path' => $repoPath, 'document_root' => $repoPath.'/public']);
        $site->setRelation('server', $server);
        $account->site_id = 'site-ulid';
        $account->setRelation('site', $site);
    }

    return $account;
}

/**
 * The single most important property of the sshd snippet. `Include` is textual,
 * so a Match block left open swallows every directive parsed after it — the
 * hardening snippet would silently stop applying to everyone on the box.
 */
test('sshd snippet closes its Match block', function () {
    $snippet = makeProvisioner(Mockery::mock(ServerSshConnectionRunner::class))->snippet();

    expect($snippet)->toContain('Match Group dply-sftp')
        ->and(trim($snippet))->toEndWith('Match all')
        ->and($snippet)->toContain('ForceCommand internal-sftp');
});

/** No per-account interpolation means no injection surface and a trivially idempotent write. */
test('sshd snippet is constant across accounts', function () {
    $provisioner = makeProvisioner(Mockery::mock(ServerSshConnectionRunner::class));

    expect($provisioner->snippet())->toBe($provisioner->snippet())
        ->and($provisioner->snippet())->not->toContain('designer');
});

/**
 * The default (`d:`) ACL is what keeps access alive across atomic deploys: each
 * deploy clones a brand-new releases/<hash>, and the kernel copies the default
 * ACL onto it at creation. Without it, FTP silently dies on the next deploy.
 */
test('grant applies a default ACL to directories and a traverse bit to the parent', function () {
    $script = makeProvisioner(Mockery::mock(ServerSshConnectionRunner::class))
        ->grantScript(makeAccount());

    expect($script)
        ->toContain("setfacl -m u:'designer':--x '/home/dply'")
        ->toContain("setfacl -R -m u:'designer':rwX '/home/dply/example.com'")
        ->toContain("find '/home/dply/example.com' -type d -exec setfacl -m d:u:'designer':rwX {} +");
});

/** Telling someone "put it in shared/" is a bad sentence when shared/ does not exist. */
test('grant creates the durable shared directory', function () {
    $script = makeProvisioner(Mockery::mock(ServerSshConnectionRunner::class))
        ->grantScript(makeAccount());

    expect($script)->toContain("mkdir -p '/home/dply/example.com'/shared");
});

/** Server-scoped accounts get the whole sites parent instead of one site tree. */
test('server scoped account targets the sites parent', function () {
    $account = makeAccount(repoPath: null);

    expect(makeProvisioner(Mockery::mock(ServerSshConnectionRunner::class))->targetPath($account))
        ->toBe('/home/dply');
});

/**
 * A newline in the password would let the remainder of the heredoc line be read
 * as a second chpasswd entry — i.e. set a password on an account we did not name.
 */
test('setPassword rejects a password that could break out of the heredoc', function () {
    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $runner->shouldNotReceive('run');

    makeProvisioner($runner)->setPassword(makeAccount(), "goodpass123\nroot:hunter2");
})->throws(\RuntimeException::class);

test('generated passwords satisfy the format the provisioner enforces', function () {
    foreach (range(1, 25) as $ignored) {
        expect(SftpAccount::generatePassword())->toMatch('/^[A-Za-z0-9]{12,128}$/');
    }
});
