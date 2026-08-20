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
 * omits Composer and Node even when PHP is installed. Mirror the BYO deploy
 * pipeline's on-demand installs so Laravel / PHP functions don't fail with
 * `composer: not found` or `sh: 1: npm: not found`.
 */
final class ServerlessBuildHostTools
{
    /**
     * Wrap a shell command so Composer and/or Node are on PATH (and installed
     * into storage/app/bin when missing) before the real command runs.
     *
     * Prefer this over a separate PHP-side install: the same shell that runs
     * `composer install` / `npm ci` also ensures the binary, so a stripped
     * worker env cannot race or miss the rewrite.
     */
    public function prepareShellCommand(string $command): string
    {
        $needsComposer = $this->commandNeedsComposer($command);
        $needsNode = $this->commandNeedsNode($command);

        if (! $needsComposer && ! $needsNode) {
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
        //
        // PATH includes mise shims and n / official-node prefixes so a Node
        // that was installed on a previous deploy is found without re-download.
        $script = '{ '
            .'export HOME='.$homeExport.'; '
            .'export COMPOSER_HOME='.$composerHomeExport.'; '
            .'export NO_COLOR=1; '
            .'export TERM=dumb; '
            .'export COMPOSER_NO_INTERACTION=1; '
            .'export PATH='.$binDirExport.':"$HOME/.local/share/mise/shims:$HOME/.n/bin:$HOME/.local/bin:$HOME/.bun/bin:/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:$PATH"; ';

        if ($needsComposer) {
            $script .= $this->composerEnsureSnippet($binDirExport, $composerHomeExport, $php);
        }

        if ($needsNode) {
            $script .= $this->nodeEnsureSnippet($binDirExport, $php, $command);
        }

        return $script.$command.'; }';
    }

    /**
     * On-demand Composer install into storage/app/bin (same shell as the build).
     */
    private function composerEnsureSnippet(string $binDirExport, string $composerHomeExport, string $php): string
    {
        return 'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer not found on Serverless build host — installing…"; '
            .'mkdir -p '.$binDirExport.' '.$composerHomeExport.'; '
            .'curl -fsSL https://getcomposer.org/installer | '.$php.' -- --install-dir='.$binDirExport.' --filename=composer; '
            .'chmod +x '.$binDirExport.'/composer 2>/dev/null || true; '
            .'}; '
            .'command -v composer >/dev/null 2>&1 || { '
            .'echo "[dply] composer is still missing after install (PATH=$PATH HOME=$HOME COMPOSER_HOME=$COMPOSER_HOME)"; '
            .'exit 127; '
            .'}; '
            .'composer --version; ';
    }

    /**
     * On-demand Node/npm (and pnpm/yarn/bun) so frontend compile after
     * `composer install` does not die with `sh: 1: npm: not found`.
     *
     * Order matches BYO {@see \App\Services\Sites\SiteDeployPipelineRunner}
     * plus the control-plane Composer pattern: mise → n → official Node
     * tarball under storage/app/bin (no root / apt / snap required).
     */
    private function nodeEnsureSnippet(string $binDirExport, string $php, string $command): string
    {
        $nodePrefixExport = escapeshellarg(storage_path('app/node'));
        $nPrefixExport = escapeshellarg(storage_path('app'));
        $manager = $this->jsPackageManagerToken($command);

        $snippet = 'command -v npm >/dev/null 2>&1 || { '
            .'echo "[dply] Node/npm not found on Serverless build host — installing…"; '
            .'if command -v mise >/dev/null 2>&1; then '
            .'echo "[dply] installing node@lts via mise…"; '
            .'mise use -g node@lts >/dev/null 2>&1 || mise install node@lts >/dev/null 2>&1; '
            .'eval "$(mise env -s bash 2>/dev/null)" 2>/dev/null || true; '
            .'export PATH="$HOME/.local/share/mise/shims:$PATH"; '
            .'fi; '
            .'}; '
            .'command -v npm >/dev/null 2>&1 || { '
            .'if command -v n >/dev/null 2>&1; then '
            .'echo "[dply] installing Node LTS via n…"; '
            .'N_PREFIX='.$nPrefixExport.' n lts; '
            .'export PATH='.$binDirExport.':"$PATH"; '
            .'fi; '
            .'}; '
            .'command -v npm >/dev/null 2>&1 || { '
            .'echo "[dply] installing official Node LTS into storage/app/bin…"; '
            .$this->officialNodeInstallSnippet($php, $nodePrefixExport, $binDirExport)
            .'}; '
            .'command -v npm >/dev/null 2>&1 || { '
            .'echo "[dply] Node/npm not found on the Serverless build host"; '
            .'exit 127; '
            .'}; '
            .'node --version; '
            .'npm --version; ';

        if (in_array($manager, ['pnpm', 'yarn'], true)) {
            $snippet .= 'if command -v corepack >/dev/null 2>&1; then '
                .'corepack enable >/dev/null 2>&1 || true; '
                .'corepack prepare '.$manager.'@latest --activate >/dev/null 2>&1 || true; '
                .'fi; '
                .'command -v '.$manager.' >/dev/null 2>&1 || npm install -g '.$manager.' >/dev/null 2>&1 || true; '
                .'command -v '.$manager.' >/dev/null 2>&1 || { '
                .'echo "[dply] '.$manager.' not found on the Serverless build host"; '
                .'exit 127; '
                .'}; ';
        }

        if ($manager === 'bun') {
            $snippet .= 'command -v bun >/dev/null 2>&1 || { '
                .'echo "[dply] bun not found on Serverless build host — installing…"; '
                .'curl -fsSL https://bun.sh/install | bash; '
                .'export PATH="$HOME/.bun/bin:$PATH"; '
                .'}; '
                .'command -v bun >/dev/null 2>&1 || { '
                .'echo "[dply] bun not found on the Serverless build host"; '
                .'exit 127; '
                .'}; ';
        }

        return $snippet;
    }

    /**
     * Download the current Node LTS tarball into storage/app/node and link
     * binaries into storage/app/bin — works without root on a queue worker.
     */
    private function officialNodeInstallSnippet(string $php, string $nodePrefixExport, string $binDirExport): string
    {
        $resolveLts = $php.' -r '.escapeshellarg(
            '$c=stream_context_create(["http"=>["timeout"=>20],"ssl"=>["verify_peer"=>true]]);'
            .'$j=@file_get_contents("https://nodejs.org/dist/index.json",false,$c);'
            .'if($j===false){exit(1);}'
            .'foreach(json_decode($j,true)?:[] as $r){if(!empty($r["lts"])&&isset($r["version"])){echo $r["version"];exit(0);}}'
            .'exit(1);'
        );

        return 'mkdir -p '.$nodePrefixExport.' '.$binDirExport.'; '
            .'NODE_VER=$('.$resolveLts.') || true; '
            .'if [ -n "$NODE_VER" ]; then '
            .'OS=$(uname -s | tr "[:upper:]" "[:lower:]"); '
            .'ARCH=$(uname -m); '
            .'case "$ARCH" in x86_64|amd64) ARCH=x64 ;; aarch64|arm64) ARCH=arm64 ;; *) ARCH=x64 ;; esac; '
            .'TARBALL="node-${NODE_VER}-${OS}-${ARCH}.tar.gz"; '
            .'curl -fsSL "https://nodejs.org/dist/${NODE_VER}/${TARBALL}" -o '.$nodePrefixExport.'/node.tar.gz '
            .'&& tar -xzf '.$nodePrefixExport.'/node.tar.gz -C '.$nodePrefixExport.' --strip-components=1 '
            .'&& rm -f '.$nodePrefixExport.'/node.tar.gz '
            .'&& ln -sfn '.$nodePrefixExport.'/bin/node '.$binDirExport.'/node '
            .'&& ln -sfn '.$nodePrefixExport.'/bin/npm '.$binDirExport.'/npm '
            .'&& ln -sfn '.$nodePrefixExport.'/bin/npx '.$binDirExport.'/npx; '
            .'export PATH='.$nodePrefixExport.'/bin:'.$binDirExport.':"$PATH"; '
            .'fi; ';
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
     * Whether a shell command likely needs Node / a JS package manager.
     */
    public function commandNeedsNode(string $command): bool
    {
        return $this->jsPackageManagerToken($command) !== null;
    }

    /**
     * First JS package-manager token in the command, if any.
     */
    public function jsPackageManagerToken(string $command): ?string
    {
        if (preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)(npm|npx|node|pnpm|yarn|bun)(\s|$)/', $command, $matches) !== 1) {
            return null;
        }

        $token = $matches[2];

        return match ($token) {
            'npx', 'node' => 'npm',
            default => $token,
        };
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
            if (is_array($info) && ! empty($info['dir'])) {
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
            storage_path('app/node/bin'),
            $home !== '' ? $home.'/.local/share/mise/shims' : null,
            $home !== '' ? $home.'/.local/bin' : null,
            $home !== '' ? $home.'/.n/bin' : null,
            $home !== '' ? $home.'/.bun/bin' : null,
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
        $candidates = [
            PHP_BINARY,
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
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
