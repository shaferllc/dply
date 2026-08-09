<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Tooling for the control-plane host that builds Serverless artifacts.
 *
 * Queue workers (systemd / deploy user) often inherit a minimal PATH that
 * omits Composer even when PHP is installed. Mirror the BYO deploy pipeline's
 * on-demand composer install so Laravel / PHP functions don't fail with
 * `composer: not found`.
 */
final class ServerlessBuildHostTools
{
    /**
     * Wrap a shell command so Composer is on PATH (and installed into
     * storage/app/bin when missing) before the real command runs.
     *
     * Prefer this over a separate PHP-side install: the same shell that runs
     * `composer install` also ensures the binary, so a stripped worker env
     * cannot race or miss the rewrite.
     */
    public function prepareShellCommand(string $command): string
    {
        if (! $this->commandNeedsComposer($command)) {
            return $command;
        }

        $binDir = storage_path('app/bin');
        File::ensureDirectoryExists($binDir);

        $binDirExport = escapeshellarg($binDir);
        $php = escapeshellarg($this->findPhp());

        // Same shape as SiteDeployPipelineRunner::ensureToolingPrefix — export
        // PATH, install via getcomposer.org when missing, then run the command.
        return '{ '
            .'export PATH='.$binDirExport.':"$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:$PATH"; '
            .'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer not found on Serverless build host — installing…"; '
            .'mkdir -p '.$binDirExport.'; '
            .'curl -fsSL https://getcomposer.org/installer | '.$php.' -- --install-dir='.$binDirExport.' --filename=composer; '
            .'chmod +x '.$binDirExport.'/composer 2>/dev/null || true; '
            .'}; '
            .'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer is still missing after install (PATH=$PATH)"; '
            .'exit 127; '
            .'}; '
            .'composer --version; '
            .$command
            .'; }';
    }

    /**
     * Resolve an absolute composer binary, installing one under storage when
     * nothing is on PATH. Kept for hooks / callers that want the path only.
     *
     * @return array{path: string, installed: bool}
     */
    public function ensureComposer(): array
    {
        $existing = $this->findComposer();
        if ($existing !== null) {
            return ['path' => $existing, 'installed' => false];
        }

        $installDir = $this->writableComposerInstallDir();
        File::ensureDirectoryExists($installDir);

        $installer = $installDir.'/composer-setup.php';
        $target = $installDir.'/composer';
        $php = $this->findPhp();

        $download = Process::fromShellCommandline(
            'curl -fsSL https://getcomposer.org/installer -o '.escapeshellarg($installer),
            $installDir,
            $this->processEnv(),
        );
        $download->setTimeout(120);
        $download->run();
        if (! $download->isSuccessful() || ! is_file($installer)) {
            throw new RuntimeException(
                'Composer is not installed on the Serverless build host, and downloading the installer failed: '
                .trim($download->getErrorOutput()."\n".$download->getOutput())
            );
        }

        $install = Process::fromShellCommandline(
            escapeshellarg($php).' '.escapeshellarg($installer)
            .' --install-dir='.escapeshellarg($installDir)
            .' --filename=composer --quiet',
            $installDir,
            $this->processEnv(),
        );
        $install->setTimeout(180);
        $install->run();
        @unlink($installer);

        if (! $install->isSuccessful() || ! is_file($target)) {
            throw new RuntimeException(
                'Composer is not installed on the Serverless build host, and the installer failed: '
                .trim($install->getErrorOutput()."\n".$install->getOutput())
            );
        }

        @chmod($target, 0755);

        return ['path' => $target, 'installed' => true];
    }

    /**
     * Whether a shell command likely needs the composer binary.
     */
    public function commandNeedsComposer(string $command): bool
    {
        return (bool) preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)composer(\s|$)/', $command);
    }

    /**
     * Rewrite a leading `composer` token to an absolute binary path so a
     * stripped worker PATH cannot miss it again mid-script.
     */
    public function withComposerBinary(string $command, string $composerBinary): string
    {
        return (string) preg_replace(
            '/(^|[;&|]\s*|&&\s*|\|\|\s*)composer(\s)/',
            '$1'.escapeshellarg($composerBinary).'$2',
            $command,
            1,
        );
    }

    /**
     * Env for local build processes — PATH includes common install locations
     * plus the storage bin where we may have just installed Composer.
     *
     * @return array<string, string>
     */
    public function processEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && ! array_key_exists($key, $env)) {
                $env[$key] = $value;
            }
        }

        $env['PATH'] = $this->enrichedPath();
        // Symfony Process may not inherit PHP_BINARY; keep it explicit for
        // nested `php composer-setup.php` calls.
        $env['PATH'] = dirname($this->findPhp()).PATH_SEPARATOR.$env['PATH'];

        return $env;
    }

    public function enrichedPath(): string
    {
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        $parts = array_filter([
            storage_path('app/bin'),
            $home !== '' ? $home.'/.local/bin' : null,
            $home !== '' ? $home.'/.composer/vendor/bin' : null,
            $home !== '' ? $home.'/.config/composer/vendor/bin' : null,
            '/usr/local/bin',
            '/opt/homebrew/bin',
            '/usr/bin',
            '/bin',
            (string) (getenv('PATH') ?: ($_SERVER['PATH'] ?? '')),
        ]);

        $merged = [];
        foreach ($parts as $part) {
            foreach (explode(PATH_SEPARATOR, $part) as $segment) {
                $segment = trim($segment);
                if ($segment !== '' && ! in_array($segment, $merged, true)) {
                    $merged[] = $segment;
                }
            }
        }

        return implode(PATH_SEPARATOR, $merged);
    }

    public function findComposer(): ?string
    {
        foreach ($this->composerCandidates() as $candidate) {
            // Accept readable files even when the execute bit is missing
            // (some deploy mounts strip +x); we invoke via php or bash PATH.
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        $which = Process::fromShellCommandline('command -v composer', null, $this->processEnv());
        $which->setTimeout(10);
        $which->run();
        $path = trim($which->getOutput());
        if ($which->isSuccessful() && $path !== '' && is_file($path)) {
            return $path;
        }

        return null;
    }

    public function findPhp(): string
    {
        $candidates = array_filter([
            PHP_BINARY !== '' ? PHP_BINARY : null,
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * @return list<string>
     */
    private function composerCandidates(): array
    {
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));

        return array_values(array_filter([
            storage_path('app/bin/composer'),
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            '/opt/homebrew/bin/composer',
            $home !== '' ? $home.'/.local/bin/composer' : null,
            $home !== '' ? $home.'/.composer/vendor/bin/composer' : null,
        ]));
    }

    private function writableComposerInstallDir(): string
    {
        foreach (['/usr/local/bin', storage_path('app/bin')] as $dir) {
            if (! is_dir($dir)) {
                try {
                    File::ensureDirectoryExists($dir);
                } catch (\Throwable) {
                    continue;
                }
            }

            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        return storage_path('app/bin');
    }
}
