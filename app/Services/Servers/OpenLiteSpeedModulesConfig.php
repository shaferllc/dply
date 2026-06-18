<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * List and toggle OpenLiteSpeed modules registered in
 * `/usr/local/lsws/conf/httpd_config.conf` as `module <name> { ... }`
 * blocks. Available `.so` files under `/usr/local/lsws/modules/` are
 * merged with blocks already present on disk (e.g. built-in `cache`).
 *
 * Enable injects a starter parameter block (module-specific when known);
 * disable removes the block. Every change validates with `lshttpd -t` and
 * reloads `lshttpd`, restoring the previous file on validation failure.
 *
 * @see https://docs.openlitespeed.org/modules/
 */
class OpenLiteSpeedModulesConfig
{
    private const REMOTE_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    private const MODULES_DIR = '/usr/local/lsws/modules';

    /**
     * Managed elsewhere — the Cache sub-tab owns the full `module cache`
     * stanza. Disable from here would break dply LSCache wiring.
     *
     * @var list<string>
     */
    public const PROTECTED_MODULES = [
        'cache',
    ];

    /**
     * Starter blocks for common modules. Unknown modules get a minimal
     * empty block; operators can tune directives in Configuration afterward.
     *
     * @var array<string, string>
     */
    public const DEFAULT_BLOCKS = [
        'modcompress' => <<<'BLOCK'
module modcompress {
  enableCompress 1
  compressLevel 6
  minCompressSize 200
}
BLOCK,
        'modgzip' => <<<'BLOCK'
module modgzip {
  enableGzip 1
}
BLOCK,
        'modsecurity' => <<<'BLOCK'
module modsecurity {
  enabled 1
}
BLOCK,
    ];

    /**
     * @return array{modules: list<array{name: string, enabled: bool, protected: bool, on_disk: bool, type: string}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $script = <<<'BASH'
set +e
ls -1 /usr/local/lsws/modules/*.so 2>/dev/null | sed -e 's|.*/||' -e 's|\.so$||' | sort -u
echo '---CONFIG---'
sudo -n cat /usr/local/lsws/conf/httpd_config.conf 2>/dev/null
BASH;
            $output = $ssh->exec($script, 20);
            if ($output === '' || $ssh->lastExecExitCode() !== 0) {
                return ['modules' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['modules' => [], 'unreadable' => true];
        }

        [$diskBlob, $configBlob] = array_pad(explode('---CONFIG---', $output, 2), 2, '');
        $onDisk = array_flip($this->splitNames($diskBlob));
        $registered = $this->parseRegisteredModuleNames($configBlob);

        $names = array_values(array_unique(array_merge(array_keys($onDisk), $registered)));
        sort($names, SORT_STRING);

        $rows = [];
        foreach ($names as $name) {
            $rows[] = [
                'name' => $name,
                'enabled' => in_array($name, $registered, true),
                'protected' => in_array($name, self::PROTECTED_MODULES, true),
                'on_disk' => isset($onDisk[$name]),
                'type' => $this->classify($name),
            ];
        }

        return ['modules' => $rows, 'unreadable' => false];
    }

    /**
     * Register or remove a module block in httpd_config.conf.
     *
     * @throws \RuntimeException
     */
    public function toggle(Server $server, string $name, bool $enable, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException('Invalid module name.');
        }
        if (! $enable && in_array($name, self::PROTECTED_MODULES, true)) {
            throw new \RuntimeException("`{$name}` is managed by the Cache tab — disable it there instead.");
        }

        $ssh = new SshConnection($server);

        $emit->step('ols-modules', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $hadBlock = $this->extractModuleBlock($contents, $name) !== null;

        if ($enable) {
            if ($hadBlock) {
                $emit->info("Module `{$name}` is already registered — no change needed.");
                $emit->success("Module `{$name}` is enabled.");

                return;
            }
            $block = $this->defaultBlockFor($name);
            $emit->step('ols-modules', "Injecting `module {$name}` block");
            $newContents = rtrim($contents, "\n")."\n\n".rtrim($block, "\n")."\n";
        } else {
            if (! $hadBlock) {
                $emit->info("Module `{$name}` is not registered — no change needed.");
                $emit->success("Module `{$name}` is disabled.");

                return;
            }
            $emit->step('ols-modules', "Removing `module {$name}` block");
            $pattern = '/^[\t ]*module\s+'.preg_quote($name, '/').'\s*\{.*?^[\t ]*\}\s*$/sm';
            $newContents = (string) preg_replace($pattern, '', $contents, 1);
            $newContents = (string) preg_replace("/\n{3,}/", "\n\n", trim($newContents))."\n";
        }

        $this->installValidateReload($ssh, $newContents, $emit, 'ols-modules');

        $emit->success(sprintf('OpenLiteSpeed reloaded — module `%s` is now %s.', $name, $enable ? 'enabled' : 'disabled'));
    }

    /**
     * @return list<string>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<string>
     */
    public function parseRegisteredModuleNames(string $config): array
    {
        if ($config === '') {
            return [];
        }
        if (preg_match_all('/^[\t ]*module\s+([A-Za-z0-9_]+)\s*\{/m', $config, $matches) !== false) {
            return array_values(array_unique($matches[1] ?? []));
        }

        return [];
    }

    public function extractModuleBlock(string $contents, string $name): ?string
    {
        $pattern = '/^[\t ]*module\s+'.preg_quote($name, '/').'\s*\{.*?^[\t ]*\}/sm';
        if (preg_match($pattern, $contents, $m) !== 1) {
            return null;
        }

        return $m[0];
    }

    public function defaultBlockFor(string $name): string
    {
        if (isset(self::DEFAULT_BLOCKS[$name])) {
            return self::DEFAULT_BLOCKS[$name]."\n";
        }

        return "module {$name} {\n}\n";
    }

    public function classify(string $name): string
    {
        if ($name === 'cache' || str_contains($name, 'compress') || str_contains($name, 'gzip')) {
            return 'perf';
        }
        if (str_contains($name, 'security') || str_contains($name, 'modsec')) {
            return 'security';
        }

        return 'other';
    }

    /**
     * @return list<string>
     */
    private function splitNames(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @throws \RuntimeException
     */
    private function installValidateReload(SshConnection $ssh, string $newContents, ConsoleEmitter $emit, string $stepPrefix): void
    {
        $emit->step($stepPrefix, 'Staging new config to /tmp');
        $tmpRemote = '/tmp/dply-httpd_config.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $writeCmd = sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null',
            escapeshellarg($encoded),
            escapeshellarg($tmpRemote),
        );
        $ssh->exec($writeCmd, 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new config on the server');
            throw new \RuntimeException('Failed to stage the new config on the server.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step($stepPrefix, 'Snapshotting current config to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step($stepPrefix, 'Installing new config at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous config left in place');
            throw new \RuntimeException('Failed to install the new config on the server.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step($stepPrefix, 'Validating with `lshttpd -t`');
        $validate = $ssh->exec('sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1; echo "__exit__:$?"', 30);
        $exit = $this->parseExitMarker($validate);
        $validateOutput = trim($this->stripExitMarker($validate));
        if ($validateOutput !== '') {
            foreach (preg_split('/\R/', $validateOutput) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
                }
            }
        }
        if ($exit !== 0) {
            $emit->step($stepPrefix, 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous config restored.');
            throw new \RuntimeException('Config validation failed; previous config restored. lshttpd -t output:'."\n".$validateOutput);
        }
        $emit->success('Config validated.');

        $emit->step($stepPrefix, 'Reloading OpenLiteSpeed (`systemctl reload lshttpd`)');
        $reload = $ssh->exec('sudo -n systemctl reload lshttpd 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = $this->parseExitMarker($reload);
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart lshttpd 2>&1', 30);
        }
    }

    private function parseExitMarker(string $output): int
    {
        if (preg_match('/__exit__:(\d+)\s*$/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return 1;
    }

    private function stripExitMarker(string $output): string
    {
        return (string) preg_replace('/__exit__:\d+\s*$/', '', $output);
    }
}
