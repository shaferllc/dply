<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Holds the source tarball `dply init` / `dply deploy` uploads for a folder
 * with no reachable git remote, between the HTTP request that receives it and
 * the queued job that builds from it.
 *
 * Local disk, deliberately: the pipeline around it is already local — checkouts
 * land in storage_path, built artifacts are pruned from local disk — so this
 * assumes the same co-location the rest of the deploy path assumes.
 * ponytail: co-located web + worker; needs shared storage the day workers move
 * to their own boxes.
 *
 * The validation here is the real boundary. The endpoint accepts archives from
 * anything holding a token, not only from dply's own CLI, so nothing the client
 * does counts as a control.
 */
final class ServerlessSourceStash
{
    private const DIRECTORY = 'app/serverless-uploads';

    /**
     * Accept a tarball, validate it, and park it under `$key`.
     *
     * @throws InvalidArgumentException when the archive is unsafe or too large
     */
    public function put(string $key, string $tarballPath): string
    {
        if (! is_file($tarballPath)) {
            throw new InvalidArgumentException('No source archive was uploaded.');
        }

        $maxBytes = (int) config('serverless.upload.max_bytes', 100 * 1024 * 1024);
        $size = (int) filesize($tarballPath);
        if ($size > $maxBytes) {
            throw new InvalidArgumentException(sprintf(
                'The source archive is %s, over the %s limit for this instance.',
                self::humanBytes($size),
                self::humanBytes($maxBytes),
            ));
        }

        $this->assertArchiveIsSafe($tarballPath);

        $destination = $this->pathFor($key);
        File::ensureDirectoryExists(dirname($destination));
        File::delete($destination);

        if (! @rename($tarballPath, $destination) && ! @copy($tarballPath, $destination)) {
            throw new InvalidArgumentException('The source archive could not be stored.');
        }

        return $destination;
    }

    /**
     * Move a dry-run stash onto the site it ended up creating, so the bytes go
     * up once rather than once per phase.
     */
    public function promote(string $fromKey, string $toKey): bool
    {
        $from = $this->pathFor($fromKey);
        if (! is_file($from)) {
            return false;
        }

        $to = $this->pathFor($toKey);
        File::ensureDirectoryExists(dirname($to));
        File::delete($to);

        return @rename($from, $to);
    }

    public function has(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    public function pathFor(string $key): string
    {
        // Keys are ours (site ids, ULIDs), but this is the one thing standing
        // between a key and the filesystem, so it is enforced rather than
        // assumed.
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid source key.');
        }

        return storage_path(self::DIRECTORY.'/'.$key.'.tar.gz');
    }

    /**
     * Unpack into `$destination`, which is emptied first.
     */
    public function materialize(string $key, string $destination): void
    {
        $archive = $this->pathFor($key);
        if (! is_file($archive)) {
            throw new InvalidArgumentException('The uploaded source for this function is no longer available. Run `dply deploy` from the project folder to upload it again.');
        }

        File::deleteDirectory($destination);
        File::ensureDirectoryExists($destination);

        // Re-validate at extraction time, not only at upload time: the archive
        // has been sitting on disk in between.
        $this->assertArchiveIsSafe($archive);

        $process = new Process(['tar', '-xzf', $archive, '-C', $destination, '--no-same-owner'], $destination, null, null, 300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException('The uploaded source archive could not be unpacked: '.trim($process->getErrorOutput()));
        }
    }

    public function forget(string $key): void
    {
        File::delete($this->pathFor($key));
    }

    /**
     * Reclaim dry-run stashes nobody came back for.
     *
     * @return int number of archives removed
     */
    public function sweepExpired(): int
    {
        $directory = storage_path(self::DIRECTORY);
        if (! is_dir($directory)) {
            return 0;
        }

        $ttlMinutes = max(1, (int) config('serverless.upload.stash_ttl_minutes', 60));
        $cutoff = time() - ($ttlMinutes * 60);
        $removed = 0;

        foreach (File::files($directory) as $file) {
            // Only dry-run stashes expire. A site's current source is what a
            // redeploy rebuilds from, so it outlives any TTL.
            if (! str_starts_with($file->getFilename(), 'stash-')) {
                continue;
            }
            if ($file->getMTime() > $cutoff) {
                continue;
            }
            if (File::delete($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Reject the archive shapes that turn extraction into arbitrary write, and
     * the ones that turn it into disk exhaustion.
     *
     * @throws InvalidArgumentException
     */
    private function assertArchiveIsSafe(string $archive): void
    {
        $names = new Process(['tar', '-tzf', $archive], null, null, null, 120);
        $names->run();
        if (! $names->isSuccessful()) {
            throw new InvalidArgumentException('The source archive is not a readable .tar.gz.');
        }

        $entries = array_values(array_filter(
            preg_split('/\r?\n/', $names->getOutput()) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));

        $maxEntries = (int) config('serverless.upload.max_entries', 20000);
        if (count($entries) > $maxEntries) {
            throw new InvalidArgumentException(sprintf(
                'The source archive holds %d entries, over the %d limit.',
                count($entries),
                $maxEntries,
            ));
        }

        foreach ($entries as $entry) {
            $name = trim($entry);

            if (str_contains($name, "\0")) {
                throw new InvalidArgumentException('The source archive contains an invalid entry name.');
            }
            if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $name) === 1) {
                throw new InvalidArgumentException(sprintf('Refusing an archive with an absolute path: %s', $name));
            }
            foreach (explode('/', $name) as $segment) {
                if ($segment === '..') {
                    throw new InvalidArgumentException(sprintf('Refusing an archive that escapes its directory: %s', $name));
                }
            }
        }

        $this->assertNoLinksOrDevices($archive);
        $this->assertUncompressedSizeWithinLimit($archive);
    }

    /**
     * Symlinks, hardlinks and device nodes all write outside the tree (or into
     * it in ways the build step then executes). The CLI passes `-h` so its own
     * archives carry none; anything else's might.
     */
    private function assertNoLinksOrDevices(string $archive): void
    {
        $verbose = new Process(['tar', '-tvzf', $archive], null, null, null, 120);
        $verbose->run();
        if (! $verbose->isSuccessful()) {
            throw new InvalidArgumentException('The source archive is not a readable .tar.gz.');
        }

        foreach (preg_split('/\r?\n/', $verbose->getOutput()) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $type = $line[0];
            if (in_array($type, ['-', 'd'], true)) {
                continue;
            }

            $label = match ($type) {
                'l' => 'a symlink',
                'h' => 'a hard link',
                'c', 'b' => 'a device node',
                'p' => 'a named pipe',
                's' => 'a socket',
                default => 'an unsupported entry type',
            };

            throw new InvalidArgumentException(sprintf(
                'Refusing an archive containing %s. Re-run the upload with symlinks dereferenced (the dply CLI does this automatically).',
                $label,
            ));
        }
    }

    /**
     * A 100 MB gzip of zeros unpacks to ~100 GB, so the compressed cap alone
     * does not bound the disk this can consume.
     *
     * Measured by decompressing to a pipe with a hard byte ceiling — `head`
     * closes the pipe once the ceiling is reached, so at most the limit is ever
     * decompressed and nothing is written to disk.
     */
    private function assertUncompressedSizeWithinLimit(string $archive): void
    {
        $limit = (int) config('serverless.upload.max_uncompressed_bytes', 400 * 1024 * 1024);

        $process = Process::fromShellCommandline(
            'gzip -dc '.escapeshellarg($archive).' | head -c '.escapeshellarg((string) ($limit + 1)).' | wc -c',
            null,
            null,
            null,
            300,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            return; // Unmeasurable; the compressed cap and entry cap still apply.
        }

        $measured = (int) trim($process->getOutput());
        if ($measured > $limit) {
            throw new InvalidArgumentException(sprintf(
                'The source archive unpacks to more than %s, over this instance\'s limit.',
                self::humanBytes($limit),
            ));
        }
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1).' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)).' MB';
        }

        return round($bytes / 1024).' KB';
    }
}
