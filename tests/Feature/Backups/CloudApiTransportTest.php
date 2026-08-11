<?php

declare(strict_types=1);

use App\Models\BackupConfiguration;
use App\Modules\Backups\Services\CloudApiCommandFactory;
use App\Modules\Backups\Services\CloudApiTokenResolver;
use Illuminate\Support\Facades\Http;

function cloudConfig(string $provider, array $config = [], string $id = 'cfg-1'): BackupConfiguration
{
    $row = new BackupConfiguration;
    $row->provider = $provider;
    $row->config = $config;
    $row->id = $id;

    return $row;
}

it('always uploads to dropbox through a session', function () {
    $factory = new CloudApiCommandFactory;
    $destination = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, ['path' => '/backups']);

    $script = reset($factory->uploadCommand($destination, 'T', '/tmp/d.sql', '/backups/db.sql', 'abc')['files']);

    // The single-shot endpoint caps at 150 MB and real dumps exceed that, so a
    // session is the only correct path — never /2/files/upload.
    expect($script)->toContain('upload_session/start')
        ->toContain('upload_session/append_v2')
        ->toContain('upload_session/finish')
        ->and($script)->not->toContain('/2/files/upload ');
});

it('splits the dump and tracks the offset across chunks', function () {
    $factory = new CloudApiCommandFactory;
    $destination = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX);

    $script = reset($factory->uploadCommand($destination, '/tmp/d.sql', '/db.sql', '/db.sql', 'abc')['files']);

    // A wrong offset silently corrupts the assembled file rather than failing.
    expect($script)->toContain('split -b')
        ->toContain('OFFSET=$((OFFSET + SIZE))');
});

it('generates valid bash for every cloud operation', function (string $provider, array $config) {
    $factory = new CloudApiCommandFactory;
    $destination = cloudConfig($provider, $config);

    $scripts = [
        reset($factory->uploadCommand($destination, 'T', '/tmp/d.sql', $factory->objectPath($destination, 'a/db.sql'), 'x')['files']),
        reset($factory->downloadCommand($destination, 'T', 'HANDLE', '/tmp/d.sql', 'x')['files']),
        reset($factory->deleteCommand($destination, 'T', 'HANDLE', 'x')['files']),
    ];

    foreach ($scripts as $i => $script) {
        $path = tempnam(sys_get_temp_dir(), 'dplytest');
        file_put_contents($path, $script);
        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $exit);
        unlink($path);

        expect($exit)->toBe(0, "script {$i} for {$provider} is not valid bash: ".implode("\n", $out));
    }
})->with([
    'dropbox' => [BackupConfiguration::PROVIDER_DROPBOX, ['path' => '/backups']],
    'google drive' => [BackupConfiguration::PROVIDER_GOOGLE_DRIVE, ['folder_id' => 'FOLDER1']],
]);

it('keeps the bearer token out of the command line', function (string $provider) {
    $factory = new CloudApiCommandFactory;
    $destination = cloudConfig($provider, []);

    $upload = $factory->uploadCommand($destination, 'SECRET-TOKEN', '/tmp/d.sql', 'db.sql', 'abc');

    expect($upload['command'])->not->toContain('SECRET-TOKEN');
    expect(reset($upload['files']))->toContain('SECRET-TOKEN');
})->with([BackupConfiguration::PROVIDER_DROPBOX, BackupConfiguration::PROVIDER_GOOGLE_DRIVE]);

it('puts a dropbox dump under the configured folder', function (?string $folder, string $expected) {
    $factory = new CloudApiCommandFactory;
    $destination = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, ['path' => $folder]);

    expect($factory->objectPath($destination, 'org/db.sql'))->toBe($expected);
})->with([
    'none' => [null, '/org/db.sql'],
    'plain' => ['backups', '/backups/org/db.sql'],
    'slashes' => ['//backups//nightly//', '/backups/nightly/org/db.sql'],
]);

it('targets a drive folder when one is configured', function () {
    $factory = new CloudApiCommandFactory;

    $withFolder = reset($factory->uploadCommand(
        cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE, ['folder_id' => 'FOLDER1']), 'T', '/tmp/d', 'db.sql', 'x'
    )['files']);
    $withoutFolder = reset($factory->uploadCommand(
        cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE, []), 'T', '/tmp/d', 'db.sql', 'x'
    )['files']);

    expect($withFolder)->toContain('FOLDER1')->toContain('parents');
    expect($withoutFolder)->not->toContain('parents');
});

it('records the drive file id from the upload response', function () {
    $factory = new CloudApiCommandFactory;
    $drive = cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE);

    // Losing this id orphans the file beyond any later download or prune.
    expect($factory->handleFromUploadOutput($drive, '{"id":"FILE-ABC","name":"db.sql"}', 'db.sql'))
        ->toBe('FILE-ABC');
});

it('fails loudly when drive returns no file id', function () {
    (new CloudApiCommandFactory)->handleFromUploadOutput(
        cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE), '{"error":"nope"}', 'db.sql'
    );
})->throws(RuntimeException::class, 'did not return a file id');

it('keeps the dropbox path as its own handle', function () {
    $factory = new CloudApiCommandFactory;

    expect($factory->handleFromUploadOutput(
        cloudConfig(BackupConfiguration::PROVIDER_DROPBOX), '', '/backups/db.sql'
    ))->toBe('/backups/db.sql');
});

it('exchanges a google refresh token for a short lived access token', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.short', 'expires_in' => 3600]),
    ]);

    $token = (new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
        ['client_id' => 'cid', 'client_secret' => 'csecret', 'refresh_token' => 'rtok'],
    ));

    expect($token)->toBe('ya29.short');

    // The client secret must be spent here and never travel to a server.
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['client_secret'] === 'csecret');
});

it('exchanges once per configuration rather than per artifact', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.short', 'expires_in' => 3600]),
    ]);

    $resolver = new CloudApiTokenResolver;
    $destination = cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE, [
        'client_id' => 'cid', 'client_secret' => 'csecret', 'refresh_token' => 'rtok',
    ]);

    $resolver->forConfiguration($destination);
    $resolver->forConfiguration($destination);
    $resolver->forConfiguration($destination);

    // A prune run touching many backups shouldn't hammer Google.
    Http::assertSentCount(1);
});

it('surfaces googles reason when the refresh token is rejected', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    (new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
        ['client_id' => 'cid', 'client_secret' => 'csecret', 'refresh_token' => 'rtok'],
    ));
})->throws(RuntimeException::class, 'Token has been expired or revoked.');

it('passes a dropbox token straight through without an exchange', function () {
    Http::fake();

    $token = (new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_DROPBOX, ['access_token' => 'sl.dropbox']
    ));

    expect($token)->toBe('sl.dropbox');
    Http::assertNothingSent();
});

it('refuses a cloud destination missing its credentials', function (string $provider, array $config) {
    (new CloudApiTokenResolver)->forConfiguration(cloudConfig($provider, $config));
})->with([
    'dropbox without token' => [BackupConfiguration::PROVIDER_DROPBOX, []],
    'drive without secret' => [BackupConfiguration::PROVIDER_GOOGLE_DRIVE, ['client_id' => 'c', 'refresh_token' => 'r']],
])->throws(RuntimeException::class);

it('prefers the dropbox refresh token over a short lived access token', function () {
    Http::fake([
        'api.dropbox.com/*' => Http::response(['access_token' => 'sl.fresh', 'expires_in' => 14400]),
    ]);

    $token = (new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_DROPBOX,
        [
            'app_key' => 'akey',
            'app_secret' => 'asecret',
            'refresh_token' => 'rtok',
            // A stale token from an earlier one-off test must not win.
            'access_token' => 'sl.stale',
        ],
    ));

    expect($token)->toBe('sl.fresh');

    // The app secret goes out as HTTP basic auth, never in the body.
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request->hasHeader('Authorization'));
});

it('surfaces dropboxs reason when the refresh token is rejected', function () {
    Http::fake([
        'api.dropbox.com/*' => Http::response([
            'error' => ['.tag' => 'invalid_grant'],
            'error_description' => 'refresh token is invalid or revoked',
        ], 400),
    ]);

    (new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_DROPBOX,
        ['app_key' => 'k', 'app_secret' => 's', 'refresh_token' => 'bad'],
    ));
})->throws(RuntimeException::class, 'refresh token is invalid or revoked');

it('still accepts a bare dropbox access token for a one off test', function () {
    Http::fake();

    expect((new CloudApiTokenResolver)->forConfiguration(cloudConfig(
        BackupConfiguration::PROVIDER_DROPBOX, ['access_token' => 'sl.oneoff']
    )))->toBe('sl.oneoff');

    Http::assertNothingSent();
});

it('caches dropbox and google tokens under separate keys', function () {
    Http::fake([
        'api.dropbox.com/*' => Http::response(['access_token' => 'dbx', 'expires_in' => 3600]),
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'goog', 'expires_in' => 3600]),
    ]);

    $resolver = new CloudApiTokenResolver;
    // Same configuration id on both providers — a shared cache key would hand
    // Google's token to Dropbox.
    $dropbox = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, ['app_key' => 'k', 'app_secret' => 's', 'refresh_token' => 'r'], 'same-id');
    $drive = cloudConfig(BackupConfiguration::PROVIDER_GOOGLE_DRIVE, ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r'], 'same-id');

    expect($resolver->forConfiguration($dropbox))->toBe('dbx');
    expect($resolver->forConfiguration($drive))->toBe('goog');
});

it('explains an expired dropbox token instead of echoing curls exit code', function () {
    $exporter = app(\App\Modules\Backups\Services\DatabaseBackupExporter::class);
    $method = new ReflectionMethod($exporter, 'cloudFailureMessage');

    $shortLived = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, ['access_token' => 'sl.expired']);
    $message = $method->invoke($exporter, $shortLived, 'Dropbox upload', 'curl: (22) The requested URL returned error: 401');

    // "curl: (22)" tells an operator nothing about what to do next.
    expect($message)->toContain('short-lived access token')
        ->toContain('reconnect')
        ->not->toContain('curl: (22)');
});

it('names the cause for other cloud failure codes', function (string $raw, string $expected) {
    $exporter = app(\App\Modules\Backups\Services\DatabaseBackupExporter::class);
    $method = new ReflectionMethod($exporter, 'cloudFailureMessage');

    $durable = cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, [
        'app_key' => 'k', 'app_secret' => 's', 'refresh_token' => 'r',
    ]);

    expect($method->invoke($exporter, $durable, 'Dropbox upload', $raw))->toContain($expected);
})->with([
    'auth' => ['curl: (22) ... error: 401', 'rejected the credentials'],
    'scope' => ['curl: (22) ... error: 403', 'lacks write permission'],
    'quota' => ['{"error_summary":"insufficient_space/..."}', 'out of space'],
]);

it('passes through an unrecognised failure verbatim', function () {
    $exporter = app(\App\Modules\Backups\Services\DatabaseBackupExporter::class);
    $method = new ReflectionMethod($exporter, 'cloudFailureMessage');

    $message = $method->invoke($exporter, cloudConfig(BackupConfiguration::PROVIDER_DROPBOX, []), 'Dropbox upload', 'connection reset by peer');

    // Don't swallow the unknown — a wrong guess is worse than the raw text.
    expect($message)->toContain('connection reset by peer');
});
