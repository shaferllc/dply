<?php

declare(strict_types=1);

namespace Tests\Unit\GitFailureRemediationSignaturesTest;

use App\Modules\Remediations\Services\RemediationCatalog;

function matchCode(?string $text): ?string
{
    return app(RemediationCatalog::class)->match($text)['code'] ?? null;
}

test('git auth failures match git_auth_failed', function (string $output) {
    expect(matchCode($output))->toBe('git_auth_failed');
})->with([
    'expired/revoked token' => 'remote: Invalid username or token. Password authentication is not supported for Git operations.',
    'no credential injected' => "fatal: could not read Username for 'https://github.com': terminal prompts disabled",
    'generic https auth' => "fatal: Authentication failed for 'https://github.com/org/repo.git/'",
    'ssh deploy key rejected' => 'git@github.com: Permission denied (publickey).',
    'private repo masquerading as missing' => 'remote: Repository not found.',
    'gitlab denied' => 'remote: HTTP Basic: Access denied',
]);

test('branch and ref failures match git_branch_missing', function (string $output) {
    expect(matchCode($output))->toBe('git_branch_missing');
})->with([
    'shallow clone missing branch' => 'fatal: Remote branch release-9 not found in upstream origin',
    'fetch missing ref' => "fatal: couldn't find remote ref refs/heads/main",
    'preflight fast-fail' => "Branch 'master' was not found on the remote repository (https://github.com/org/repo.git).",
]);

test('other recognized clone failures map to their codes', function (string $output, string $code) {
    expect(matchCode($output))->toBe($code);
})->with([
    'host key' => ['Host key verification failed.', 'git_host_key_failed'],
    'dir exists' => ["fatal: destination path '/home/dply/site/releases/x' already exists and is not an empty directory.", 'git_clone_dir_exists'],
    'disk full' => ['error: unable to write sha1 file: No space left on device', 'server_disk_full'],
    'dns' => ['fatal: unable to access repo: Could not resolve host: github.com', 'git_network_unreachable'],
    'hung transfer' => ['fatal: the remote end hung up unexpectedly', 'git_network_unreachable'],
]);

test('the auth signature does not shadow the database remediation', function () {
    expect(matchCode('SQLSTATE[08006] connection to server at "10.0.0.2" failed'))
        ->toBe('database_connection_failed');
});

test('unrecognized output matches nothing', function () {
    expect(matchCode('npm ERR! missing script: build'))->toBeNull();
});

test('the git_auth_failed fix is a link action to source control settings', function () {
    $remediation = app(RemediationCatalog::class)->find('git_auth_failed');

    expect($remediation)->not->toBeNull();
    $action = collect($remediation['actions'])->firstWhere('recommended', true);
    expect($action['route'] ?? null)->toBe('profile.source-control');
    expect($action['script'] ?? null)->toBeNull();
});
