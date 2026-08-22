<?php

namespace Tests\Unit\Serverless\ServerlessSourceStashTest;

use App\Modules\Serverless\Services\ServerlessSourceStash;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * The upload endpoint accepts archives from anything holding a token, not only
 * from dply's own CLI — so nothing the client does counts as a control. These
 * are the shapes that turn "unpack the user's folder" into arbitrary write or
 * disk exhaustion.
 */
function workspace(string $name): string
{
    $path = storage_path('framework/testing/stash-'.$name.'-'.uniqid());
    File::ensureDirectoryExists($path);

    return $path;
}

/** Build a .tar.gz from a callback that populates a staging directory. */
function archiveOf(callable $populate): string
{
    $staging = workspace('src');
    $populate($staging);

    $archive = workspace('out').'/source.tar.gz';
    File::ensureDirectoryExists(dirname($archive));

    (new Process(['tar', '-czf', $archive, '-C', $staging, '.']))->mustRun();

    return $archive;
}

/**
 * Hand-write a tar with an arbitrary entry name.
 *
 * Neither GNU tar nor bsdtar will *store* `../` or a leading `/` without
 * coaxing, and they disagree on the flag for it — but an attacker is not using
 * tar. Emitting the ustar header directly is both portable and closer to the
 * real thing.
 */
function craftedArchive(string $entryName, string $body = "pwned\n"): string
{
    $header = str_pad(substr($entryName, 0, 100), 100, "\0")   // name
        .str_pad('0000644', 8, "\0")                           // mode
        .str_pad('0000000', 8, "\0")                           // uid
        .str_pad('0000000', 8, "\0")                           // gid
        .str_pad(decoct(strlen($body)), 11, '0', STR_PAD_LEFT)."\0"
        .str_pad(decoct(time()), 11, '0', STR_PAD_LEFT)."\0"
        .str_repeat(' ', 8)                                     // checksum placeholder
        .'0'                                                    // typeflag: regular file
        .str_repeat("\0", 100)                                 // linkname
        .'ustar'."\0".'00'
        .str_repeat("\0", 32 + 32 + 8 + 8 + 155);

    $header = str_pad($header, 512, "\0");

    $checksum = 0;
    for ($i = 0; $i < 512; $i++) {
        $checksum += ord($header[$i]);
    }
    $header = substr_replace(
        $header,
        str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ",
        148,
        8,
    );

    $tar = $header
        .str_pad($body, (int) (ceil(strlen($body) / 512) * 512), "\0")
        .str_repeat("\0", 1024); // two empty blocks terminate the archive

    $archive = workspace('crafted').'/source.tar.gz';
    File::ensureDirectoryExists(dirname($archive));
    File::put($archive, (string) gzencode($tar));

    return $archive;
}

afterEach(function () {
    File::deleteDirectory(storage_path('app/serverless-uploads'));
});

it('accepts and unpacks an ordinary project folder', function () {
    $archive = archiveOf(function (string $dir) {
        File::put($dir.'/main.js', "export function main() { return {}; }\n");
        File::ensureDirectoryExists($dir.'/src');
        File::put($dir.'/src/app.js', "// app\n");
    });

    $stash = app(ServerlessSourceStash::class);
    $stash->put('site-testsite', $archive);

    expect($stash->has('site-testsite'))->toBeTrue();

    $target = workspace('dest');
    $stash->materialize('site-testsite', $target);

    expect($target.'/main.js')->toBeFile()
        ->and($target.'/src/app.js')->toBeFile();
});

it('refuses an archive that escapes its directory', function () {
    $archive = craftedArchive('../../evil.sh');

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-evil', $archive))
        ->toThrow(InvalidArgumentException::class, 'escapes its directory');
});

it('refuses an archive with an absolute path', function () {
    $archive = craftedArchive('/etc/cron.d/evil');

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-abs', $archive))
        ->toThrow(InvalidArgumentException::class, 'absolute path');
});

it('refuses an archive containing a symlink', function () {
    $archive = archiveOf(function (string $dir) {
        File::put($dir.'/real.txt', "fine\n");
        symlink('/etc/passwd', $dir.'/leak');
    });

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-link', $archive))
        ->toThrow(InvalidArgumentException::class, 'symlink');
});

it('refuses an archive with more entries than the instance allows', function () {
    config()->set('serverless.upload.max_entries', 3);

    $archive = archiveOf(function (string $dir) {
        foreach (range(1, 10) as $i) {
            File::put($dir.'/file-'.$i.'.txt', "x\n");
        }
    });

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-many', $archive))
        ->toThrow(InvalidArgumentException::class, 'entries');
});

it('refuses a decompression bomb even though it is small compressed', function () {
    // The compressed cap alone does not bound the disk this consumes: a few
    // hundred KB of zeros unpacks to megabytes.
    config()->set('serverless.upload.max_uncompressed_bytes', 1024);

    $archive = archiveOf(function (string $dir) {
        File::put($dir.'/big.bin', str_repeat("\0", 5 * 1024 * 1024));
    });

    expect(filesize($archive))->toBeLessThan(1024 * 1024);

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-bomb', $archive))
        ->toThrow(InvalidArgumentException::class, 'unpacks to more than');
});

it('refuses an archive over the size cap', function () {
    config()->set('serverless.upload.max_bytes', 10);

    $archive = archiveOf(function (string $dir) {
        File::put($dir.'/main.js', str_repeat('x', 4096));
    });

    expect(fn () => app(ServerlessSourceStash::class)->put('stash-big', $archive))
        ->toThrow(InvalidArgumentException::class, 'over the');
});

it('rejects a key that would escape the upload directory', function () {
    expect(fn () => app(ServerlessSourceStash::class)->pathFor('../../etc/passwd'))
        ->toThrow(InvalidArgumentException::class, 'Invalid source key');
});

it('promotes a dry-run stash onto the site it created, so the bytes go up once', function () {
    $archive = archiveOf(function (string $dir) {
        File::put($dir.'/main.js', "// hi\n");
    });

    $stash = app(ServerlessSourceStash::class);
    $stash->put('stash-abc', $archive);

    expect($stash->promote('stash-abc', 'site-xyz'))->toBeTrue()
        ->and($stash->has('site-xyz'))->toBeTrue()
        ->and($stash->has('stash-abc'))->toBeFalse();
});

it('sweeps abandoned dry-run stashes but never a site\'s own source', function () {
    $make = fn () => archiveOf(function (string $dir) {
        File::put($dir.'/main.js', "// hi\n");
    });

    // `put()` moves the uploaded temp file rather than copying it, so each
    // call needs its own archive — which is what an upload actually provides.
    $stash = app(ServerlessSourceStash::class);
    $stash->put('stash-old', $make());
    $stash->put('site-keepme', $make());

    // Age the abandoned one past the TTL.
    touch($stash->pathFor('stash-old'), time() - 7200);
    config()->set('serverless.upload.stash_ttl_minutes', 60);

    expect($stash->sweepExpired())->toBe(1)
        ->and($stash->has('stash-old'))->toBeFalse()
        // A site's current source is what its next redeploy rebuilds from.
        ->and($stash->has('site-keepme'))->toBeTrue();
});
