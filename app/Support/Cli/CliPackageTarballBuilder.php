<?php

declare(strict_types=1);

namespace App\Support\Cli;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Builds an npm-installable CLI tarball (package/ prefix) without calling npm pack.
 *
 * PHP-FPM often lacks npm on PATH; plain tar with the npm-pack directory layout works
 * everywhere tar is available.
 */
final class CliPackageTarballBuilder
{
    private const CACHE_KEY = 'cli.package.tarball';

    private const CACHE_SECONDS = 300;

    /** @var list<string> */
    private const PACKAGE_PATHS = [
        'bin',
        'src',
        'package.json',
        'README.md',
    ];

    public function cachedContents(?string $baseUrl = null): string
    {
        $resolved = $this->resolveBaseUrl($baseUrl);
        $cacheKey = self::CACHE_KEY.'.'.sha1($resolved);

        return Cache::remember(
            $cacheKey,
            self::CACHE_SECONDS,
            fn (): string => $this->buildContents($resolved),
        );
    }

    public function buildContents(?string $baseUrl = null): string
    {
        $packageDir = base_path('packages/dply-cli');
        if (! is_dir($packageDir)) {
            throw new RuntimeException('CLI package directory is missing.');
        }

        $workDir = storage_path('app/cli-pack/build-'.uniqid('pack-', true));
        $packageRoot = $workDir.'/package';
        $archivePath = $workDir.'/dply-cli.tgz';

        File::ensureDirectoryExists($packageRoot);

        try {
            foreach (self::PACKAGE_PATHS as $relative) {
                $source = $packageDir.'/'.$relative;
                $target = $packageRoot.'/'.$relative;

                if (is_dir($source)) {
                    File::copyDirectory($source, $target);
                } elseif (is_file($source)) {
                    File::copy($source, $target);
                } else {
                    throw new RuntimeException("CLI package file missing: {$relative}");
                }
            }

            $defaultsPath = $packageRoot.'/src/instance-defaults.json';
            File::put($defaultsPath, json_encode([
                'baseUrl' => $this->resolveBaseUrl($baseUrl),
                // Stamped so an installed CLI can tell whether it is the build
                // this instance is serving. `version` in package.json is
                // hand-maintained and in practice never moves, which would make
                // `dply update` report "up to date" forever.
                'build' => $this->buildId(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $process = new Process(['tar', '-czf', $archivePath, '-C', $workDir, 'package']);
            $process->mustRun();

            $contents = file_get_contents($archivePath);
            if ($contents === false || $contents === '') {
                throw new RuntimeException('Could not read built CLI archive.');
            }

            return $contents;
        } catch (ProcessFailedException $e) {
            throw new RuntimeException('tar failed while building CLI archive: '.$e->getMessage(), 0, $e);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Content fingerprint of the CLI source that would be packed. Any change to
     * a shipped file moves it, so adding a command is enough to make every
     * installed CLI see an update — no version bump to remember.
     *
     * instance-defaults.json is excluded on purpose: it is rewritten per origin
     * at pack time, so including it would make the same code fingerprint
     * differently for every host that serves it.
     */
    public function buildId(): string
    {
        $packageDir = base_path('packages/dply-cli');
        $parts = [];

        foreach (self::PACKAGE_PATHS as $relative) {
            $source = $packageDir.'/'.$relative;

            $files = is_dir($source)
                ? File::allFiles($source)
                : (is_file($source) ? [new \SplFileInfo($source)] : []);

            foreach ($files as $file) {
                $path = $file->getPathname();
                if (str_ends_with($path, '/src/instance-defaults.json')) {
                    continue;
                }
                $parts[substr($path, strlen($packageDir) + 1)] = (string) md5_file($path);
            }
        }

        ksort($parts);

        return substr(sha1(json_encode($parts, JSON_THROW_ON_ERROR)), 0, 12);
    }

    private function resolveBaseUrl(?string $baseUrl): string
    {
        $candidate = filled($baseUrl)
            ? $baseUrl
            : (string) config('cli.default_base_url', config('app.url'));

        return rtrim($candidate, '/');
    }
}
