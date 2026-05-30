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

    public function cachedContents(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): string => $this->buildContents());
    }

    public function buildContents(): string
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
                'baseUrl' => rtrim((string) config('cli.default_base_url', config('app.url')), '/'),
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
}
