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
        $home = $this->resolveHome();
        $composerHome = $this->resolveComposerHome($home);
        File::ensureDirectoryExists($binDir);
        File::ensureDirectoryExists($composerHome);

        $binDirExport = escapeshellarg($binDir);
        $homeExport = escapeshellarg($home);
        $composerHomeExport = escapeshellarg($composerHome);
        $php = escapeshellarg($this->findPhp());

        // Queue workers (systemd) often have no HOME — Composer refuses to run
        // without HOME or COMPOSER_HOME. Export both before install + install.
        //
        // NO_COLOR / TERM=dumb keep build tools from painting the deploy log
        // with ANSI escapes and progress-bar redraws. The log is read in a
        // browser, where those render as literal `[39m` noise; the exports stop
        // it at the source rather than scrubbing it back out afterwards.
        return '{ '
            .'export HOME='.$homeExport.'; '
            .'export COMPOSER_HOME='.$composerHomeExport.'; '
            .'export NO_COLOR=1; '
            .'export TERM=dumb; '
            .'export COMPOSER_NO_INTERACTION=1; '
            .'export PATH='.$binDirExport.':"$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:$PATH"; '
            .'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer not found on Serverless build host — installing…"; '
            .'mkdir -p '.$binDirExport.' '.$composerHomeExport.'; '
            .'curl -fsSL https://getcomposer.org/installer | '.$php.' -- --install-dir='.$binDirExport.' --filename=composer; '
            .'chmod +x '.$binDirExport.'/composer 2>/dev/null || true; '
            .'}; '
            .'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer is still missing after install (PATH=$PATH HOME=$HOME COMPOSER_HOME=$COMPOSER_HOME)"; '
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

        $home = $this->resolveHome();
        $composerHome = $this->resolveComposerHome($home);
        File::ensureDirectoryExists($composerHome);

        // Always set — systemd queue workers often omit HOME, and Composer
        // aborts with "HOME or COMPOSER_HOME environment variable must be set".
        $env['HOME'] = $home;
        $env['COMPOSER_HOME'] = $composerHome;
        $env['PATH'] = dirname($this->findPhp()).PATH_SEPARATOR.$this->enrichedPath($home);

        // Deploy logs are read in a browser, not a terminal: colour codes show
        // up as literal `[39m` and progress bars arrive as hundreds of redraws.
        // Composer, npm, and pip all honour these.
        $env['NO_COLOR'] = '1';
        $env['TERM'] = 'dumb';
        $env['COMPOSER_NO_INTERACTION'] = '1';

        return $env;
    }

    /**
     * Home directory for Composer / cache. Prefer the process HOME, then the
     * OS user home, then a writable storage fallback (queue workers).
     */
    public function resolveHome(): string
    {
        foreach ([
            getenv('HOME') ?: null,
            $_SERVER['HOME'] ?? null,
            $_ENV['HOME'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return rtrim($candidate, DIRECTORY_SEPARATOR);
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && ! empty($info['dir']) && is_string($info['dir'])) {
                return rtrim($info['dir'], DIRECTORY_SEPARATOR);
            }
        }

        $fallback = storage_path('app/serverless-build-home');
        File::ensureDirectoryExists($fallback);

        return $fallback;
    }

    public function resolveComposerHome(?string $home = null): string
    {
        $configured = getenv('COMPOSER_HOME') ?: ($_SERVER['COMPOSER_HOME'] ?? $_ENV['COMPOSER_HOME'] ?? null);
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        // Keep Composer cache under storage so deploy users without a real
        // home directory still have a writable place for auth/cache files.
        $dir = storage_path('app/composer-home');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function enrichedPath(?string $home = null): string
    {
        $home ??= $this->resolveHome();
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
        $home = $this->resolveHome();

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
