<?php

declare(strict_types=1);

use App\Models\BackupConfiguration;
use App\Modules\Backups\Services\FileTransportCommandFactory;

/**
 * The command factory is pure, so everything that usually needs a live endpoint
 * to catch — quoting, URL assembly, path joining, whether a password leaks into
 * argv — is testable here.
 */
function transportConfig(string $provider, array $config = []): BackupConfiguration
{
    $row = new BackupConfiguration;
    $row->provider = $provider;
    $row->config = $config;

    return $row;
}

it('never puts credentials in the command line', function (string $provider, array $config) {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig($provider, $config);

    $upload = $factory->uploadCommand($destination, '/tmp/dump.sql', 'dumps/db.sql', 'abc123');

    // The whole reason secrets go through a --config file: argv is world-readable.
    expect($upload['command'])->not->toContain('hunter2')
        ->and($upload['command'])->not->toContain('BEGIN OPENSSH PRIVATE KEY');
})->with([
    'sftp' => [BackupConfiguration::PROVIDER_SFTP, ['host' => 'h', 'username' => 'u', 'password' => 'hunter2']],
    'ftp' => [BackupConfiguration::PROVIDER_FTP, ['host' => 'h', 'username' => 'u', 'password' => 'hunter2']],
    'rclone' => [BackupConfiguration::PROVIDER_RCLONE, ['remote_name' => 'r', 'config' => "[r]\npass = hunter2"]],
]);

it('passes the password through a curl config file instead', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, [
        'host' => 'backup.example.com',
        'username' => 'deploy',
        'password' => 'hunter2',
    ]);

    $upload = $factory->uploadCommand($destination, '/tmp/dump.sql', 'db.sql', 'abc123');

    expect($upload['files'])->toHaveCount(1);
    expect(reset($upload['files']))->toContain('user = "deploy:hunter2"');
});

it('escapes quotes and backslashes in a curl config value', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_FTP, [
        'host' => 'h',
        'username' => 'de"ploy',
        'password' => 'a\\b"c',
    ]);

    $upload = $factory->uploadCommand($destination, '/tmp/d.sql', 'd.sql', 'x');

    // A raw quote would terminate the value early and silently truncate the password.
    expect(reset($upload['files']))->toContain('user = "de\\"ploy:a\\\\b\\"c"');
});

it('builds a scheme and port correct url per protocol', function () {
    $factory = new FileTransportCommandFactory;

    $sftp = $factory->uploadCommand(
        transportConfig(BackupConfiguration::PROVIDER_SFTP, ['host' => 'h.example', 'username' => 'u']),
        '/tmp/d.sql',
        'a/b.sql',
        'x',
    );
    $ftp = $factory->uploadCommand(
        transportConfig(BackupConfiguration::PROVIDER_FTP, ['host' => 'h.example', 'username' => 'u', 'port' => 2121]),
        '/tmp/d.sql',
        'a/b.sql',
        'x',
    );

    expect($sftp['command'])->toContain("'sftp://h.example:22/a/b.sql'");
    expect($ftp['command'])->toContain("'ftp://h.example:2121/a/b.sql'");
});

it('falls back to the default port when the configured one is out of range', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, [
        'host' => 'h', 'username' => 'u', 'port' => 99999,
    ]);

    expect($factory->uploadCommand($destination, '/tmp/d', 'd', 'x')['command'])
        ->toContain('sftp://h:22/');
});

it('joins the destination base path with the object key', function (?string $base, string $expected) {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, [
        'host' => 'h', 'username' => 'u', 'path' => $base,
    ]);

    expect($factory->objectPath($destination, 'org/db/dump.sql'))->toBe($expected);
})->with([
    'no base' => [null, 'org/db/dump.sql'],
    'plain' => ['backups', 'backups/org/db/dump.sql'],
    'absolute' => ['/srv/backups', '/srv/backups/org/db/dump.sql'],
    'trailing slash' => ['backups/', 'backups/org/db/dump.sql'],
    'double slashes' => ['//srv//backups//', '/srv/backups/org/db/dump.sql'],
]);

it('writes the private key to its own file for sftp key auth', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, [
        'host' => 'h',
        'username' => 'u',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\nabc',
    ]);

    $upload = $factory->uploadCommand($destination, '/tmp/d.sql', 'd.sql', 'abc123');
    $files = $factory->withKeyFile($destination, $upload['files'], 'abc123');

    expect($files)->toHaveKey('/tmp/dply-xfer-abc123.key');
    // OpenSSH rejects a key file with no trailing newline.
    expect($files['/tmp/dply-xfer-abc123.key'])->toEndWith("\n");
    expect(reset($upload['files']))->toContain('key = "/tmp/dply-xfer-abc123.key"');
});

it('refuses to let a scratch id escape the temp directory', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, ['host' => 'h', 'username' => 'u']);

    $upload = $factory->uploadCommand($destination, '/tmp/d.sql', 'd.sql', '../../etc/cron.d/evil');

    // A traversal here would write a credential file to an attacker-chosen path.
    foreach (array_keys($upload['files']) as $path) {
        expect($path)->toStartWith('/tmp/dply-xfer-');
        expect($path)->not->toContain('..');
    }
});

it('deletes a single rclone object rather than a directory', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_RCLONE, [
        'remote_name' => 'store', 'config' => "[store]\ntype = sftp",
    ]);

    // `rclone delete` targets a directory's contents and fails on a file path
    // with "directory not found" — retention would then never prune anything.
    $command = $factory->deleteCommand($destination, 'dumps/db.sql', 'x')['command'];

    expect($command)->toContain('deletefile')
        ->toContain("'store:dumps/db.sql'");
});

it('uses copyto so the rclone destination is the file, not a directory', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_RCLONE, [
        'remote_name' => 'wasabi',
        'config' => "[wasabi]\ntype = s3",
    ]);

    $upload = $factory->uploadCommand($destination, '/tmp/d.sql', 'dumps/d.sql', 'x');
    $download = $factory->downloadCommand($destination, 'dumps/d.sql', '/tmp/d.sql', 'x');

    expect($upload['command'])->toContain('copyto')
        ->toContain("'/tmp/d.sql' 'wasabi:dumps/d.sql'");
    // Download is the same verb with the operands reversed.
    expect($download['command'])->toContain("'wasabi:dumps/d.sql' '/tmp/d.sql'");
});

it('creates missing directories on upload so a dated prefix works on a bare destination', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_FTP, ['host' => 'h', 'username' => 'u']);

    expect($factory->uploadCommand($destination, '/tmp/d.sql', '2026/08/d.sql', 'x')['command'])
        ->toContain('--ftp-create-dirs');
});

it('deletes with the protocol native verb', function () {
    $factory = new FileTransportCommandFactory;

    expect($factory->deleteCommand(
        transportConfig(BackupConfiguration::PROVIDER_SFTP, ['host' => 'h', 'username' => 'u']), 'a/b.sql', 'x'
    )['command'])->toContain("'rm a/b.sql'");

    expect($factory->deleteCommand(
        transportConfig(BackupConfiguration::PROVIDER_FTP, ['host' => 'h', 'username' => 'u']), 'a/b.sql', 'x'
    )['command'])->toContain("'DELE a/b.sql'");
});

it('cleans up every secret file it asked the caller to write', function () {
    $factory = new FileTransportCommandFactory;
    $destination = transportConfig(BackupConfiguration::PROVIDER_SFTP, [
        'host' => 'h', 'username' => 'u', 'private_key' => 'k',
    ]);

    $upload = $factory->uploadCommand($destination, '/tmp/d.sql', 'd.sql', 'abc');
    $files = $factory->withKeyFile($destination, $upload['files'], 'abc');
    $cleanup = $factory->cleanupCommand($files);

    foreach (array_keys($files) as $path) {
        expect($cleanup)->toContain(escapeshellarg($path));
    }
});

it('rejects a provider it cannot move bytes for', function () {
    $factory = new FileTransportCommandFactory;

    expect(FileTransportCommandFactory::supports(BackupConfiguration::PROVIDER_AWS_S3))->toBeFalse();

    $factory->uploadCommand(
        transportConfig(BackupConfiguration::PROVIDER_AWS_S3, []), '/tmp/d', 'd', 'x'
    );
})->throws(InvalidArgumentException::class);

it('refuses a destination with no host', function () {
    (new FileTransportCommandFactory)->uploadCommand(
        transportConfig(BackupConfiguration::PROVIDER_SFTP, ['username' => 'u']), '/tmp/d', 'd', 'x'
    );
})->throws(InvalidArgumentException::class, 'missing a host');

it('refuses an rclone destination with no remote name', function () {
    (new FileTransportCommandFactory)->uploadCommand(
        transportConfig(BackupConfiguration::PROVIDER_RCLONE, ['config' => '[x]']), '/tmp/d', 'd', 'x'
    );
})->throws(InvalidArgumentException::class, 'remote name');
