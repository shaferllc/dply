<?php

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ServerPhpConfigEditor
{
    /**
     * @return array{
     *     version: string,
     *     target: string,
     *     label: string,
     *     path: string,
     *     validator: string,
     *     verification_label: string,
     *     reload_guidance: string
     * }
     */
    public function resolveEditableTarget(Server $server, string $version, string $target): array
    {
        $server = $server->fresh() ?? $server;
        $this->guardServerReady($server);

        $version = $this->normalizeVersionId($version);
        $target = trim($target);

        if ($version === null) {
            throw new \RuntimeException('A valid PHP version is required.');
        }

        if (! in_array($version, $this->installedVersionIds($server), true)) {
            throw new \RuntimeException('Install PHP '.$version.' before editing its shared config.');
        }

        return match ($target) {
            'cli_ini' => [
                'version' => $version,
                'target' => $target,
                'label' => 'CLI ini',
                'path' => "/etc/php/{$version}/cli/php.ini",
                'validator' => "php{$version}",
                'verification_label' => 'CLI ini validation',
                'reload_guidance' => __('Reload is not required for CLI ini changes, but new CLI processes will use the updated file.'),
            ],
            'fpm_ini' => [
                'version' => $version,
                'target' => $target,
                'label' => 'FPM ini',
                'path' => "/etc/php/{$version}/fpm/php.ini",
                'validator' => "php-fpm{$version}",
                'verification_label' => 'FPM ini validation',
                'reload_guidance' => __('PHP-FPM :version will be reloaded automatically after saving.', ['version' => $version]),
            ],
            'pool_config' => [
                'version' => $version,
                'target' => $target,
                'label' => 'Pool config',
                'path' => "/etc/php/{$version}/fpm/pool.d/www.conf",
                'validator' => "php-fpm{$version}",
                'verification_label' => 'Pool config validation',
                'reload_guidance' => __('PHP-FPM :version will be reloaded automatically after saving.', ['version' => $version]),
            ],
            default => throw new \RuntimeException('Unknown PHP config target.'),
        };
    }

    /**
     * @return array{version: string, target: string, label: string, path: string, content: string, reload_guidance: string}
     */
    public function openTarget(Server $server, string $version, string $target): array
    {
        $resolved = $this->resolveEditableTarget($server, $version, $target);
        $content = $this->readRemoteTarget($server->fresh() ?? $server, $resolved);

        return [
            'version' => $resolved['version'],
            'target' => $resolved['target'],
            'label' => $resolved['label'],
            'path' => $resolved['path'],
            'content' => $content,
            'reload_guidance' => $resolved['reload_guidance'],
        ];
    }

    /**
     * @return array{message: string, reload_guidance: string, verification_output: ?string, output?: ?string}
     */
    public function saveTarget(Server $server, string $version, string $target, string $content): array
    {
        $version = $this->normalizeVersionId($version);
        $target = trim($target);

        if ($version === null || $target === '') {
            throw new \RuntimeException('A PHP version and config target are required.');
        }

        $lock = Cache::lock($this->serverMutationLockKey($server), 150);

        if (! $lock->get()) {
            throw new \RuntimeException('Another PHP server mutation is already running for this server.');
        }

        try {
            $server = $server->fresh() ?? $server;
            $resolved = $this->resolveEditableTarget($server, $version, $target);
            $verification = $this->verifyProposedContent($server, $resolved, $content);
            $this->replaceRemoteTarget($server, $resolved, $content);
            $reloadMessage = $this->reloadRuntimeIfNeeded($server, $resolved);

            return [
                'message' => $reloadMessage ?? __(':label saved for PHP :version.', [
                    'label' => $resolved['label'],
                    'version' => $resolved['version'],
                ]),
                'reload_guidance' => $resolved['reload_guidance'],
                'verification_output' => $verification['output'] ?? null,
                'output' => trim(implode("\n\n", array_filter([
                    $verification['output'] ?? null,
                    $reloadMessage,
                ]))) ?: null,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{label: string, path: string, version: string, target: string}  $target
     */
    protected function readRemoteTarget(Server $server, array $target): string
    {
        $output = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($this->buildReadScript($server, $target), 60)
        );

        if (str_starts_with($output, '__DPLY_MISSING__')) {
            throw new \RuntimeException(__(':label is not available for PHP :version on this server.', [
                'label' => $target['label'],
                'version' => $target['version'],
            ]));
        }

        return $output;
    }

    /**
     * @param  array{label: string, path: string, version: string, target: string, validator: string}  $target
     * @return array{output: ?string}
     */
    protected function verifyProposedContent(Server $server, array $target, string $content): array
    {
        try {
            $output = app(ServerSshConnectionRunner::class)->run(
                $server,
                fn ($ssh): string => $ssh->exec($this->buildValidationScript($server, $target, $content), 120)
            );
        } catch (\Throwable $e) {
            throw new ServerPhpConfigValidationException(
                __(':label validation failed. The live file was not replaced.', ['label' => $target['label']]),
                trim($e->getMessage())
            );
        }

        return [
            'output' => trim($output) !== '' ? trim($output) : null,
        ];
    }

    /**
     * @param  array{label: string, path: string, version: string, target: string}  $target
     */
    protected function replaceRemoteTarget(Server $server, array $target, string $content): void
    {
        app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $target, $content): null {
                $ssh->exec($this->buildReplaceScript($server, $target, $content), 120);

                return null;
            }
        );
    }

    /**
     * @param  array{label: string, path: string, version: string, target: string}  $target
     */
    protected function reloadRuntimeIfNeeded(Server $server, array $target): ?string
    {
        if (! in_array($target['target'], ['fpm_ini', 'pool_config'], true)) {
            return null;
        }

        app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $target): null {
                $ssh->exec($this->buildReloadScript($server, $target['version']), 120);

                return null;
            }
        );

        return __(':label saved and PHP-FPM :version reloaded.', [
            'label' => $target['label'],
            'version' => $target['version'],
        ]);
    }

    protected function serverMutationLockKey(Server $server): string
    {
        return 'server-php-package-action:'.$server->id;
    }

    /**
     * @return list<string>
     */
    protected function installedVersionIds(Server $server): array
    {
        $inventory = app(ServerPhpManager::class)->cachedInventory($server);

        return array_values(array_filter(array_column($inventory['installed_versions'], 'id'), 'is_string'));
    }

    protected function guardServerReady(Server $server): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before editing PHP config.');
        }
    }

    protected function normalizeVersionId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        return preg_match('/(\d+\.\d+)/', $value, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  array{path: string}  $target
     */
    protected function buildReadScript(Server $server, array $target): string
    {
        $path = escapeshellarg($target['path']);
        $inner = "if [ ! -f {$path} ]; then printf '__DPLY_MISSING__'; exit 0; fi\ncat {$path}";

        return $this->withPrivilege($server, $inner);
    }

    /**
     * @param  array{path: string, target: string, version: string, validator: string}  $target
     */
    protected function buildValidationScript(Server $server, array $target, string $content): string
    {
        $targetPath = escapeshellarg($target['path']);
        $encoded = base64_encode($content);
        $validator = escapeshellarg($target['validator']);
        $version = $target['version'];
        $mainFpmConfig = escapeshellarg("/etc/php/{$version}/fpm/php-fpm.conf");
        $fpmIniPath = escapeshellarg("/etc/php/{$version}/fpm/php.ini");

        $command = match ($target['target']) {
            'cli_ini' => <<<'BASH'
if ! command -v __VALIDATOR__ >/dev/null 2>&1; then
  echo "CLI validator command not found."
  exit 1
fi
__VALIDATOR__ -n -c "$candidate_path" -m >/tmp/dply-php-verify.log 2>&1 || {
  cat /tmp/dply-php-verify.log
  exit 1
}
cat /tmp/dply-php-verify.log
BASH,
            'fpm_ini' => <<<'BASH'
if ! command -v __VALIDATOR__ >/dev/null 2>&1; then
  echo "PHP-FPM validator command not found."
  exit 1
fi
__VALIDATOR__ -tt -y __MAIN_FPM_CONFIG__ -c "$candidate_path" >/tmp/dply-php-verify.log 2>&1 || {
  cat /tmp/dply-php-verify.log
  exit 1
}
cat /tmp/dply-php-verify.log
BASH,
            'pool_config' => <<<'BASH'
if ! command -v __VALIDATOR__ >/dev/null 2>&1; then
  echo "PHP-FPM validator command not found."
  exit 1
fi
tmp_main_conf="$tmp_dir/php-fpm.conf"
sed "s#^include=.*#include=$candidate_path#" __MAIN_FPM_CONFIG__ > "$tmp_main_conf"
__VALIDATOR__ -tt -y "$tmp_main_conf" -c __FPM_INI_PATH__ >/tmp/dply-php-verify.log 2>&1 || {
  cat /tmp/dply-php-verify.log
  exit 1
}
cat /tmp/dply-php-verify.log
BASH,
            default => throw new \RuntimeException('Unknown PHP config target.'),
        };

        $command = str_replace(
            ['__VALIDATOR__', '__MAIN_FPM_CONFIG__', '__FPM_INI_PATH__'],
            [$target['validator'], "/etc/php/{$version}/fpm/php-fpm.conf", "/etc/php/{$version}/fpm/php.ini"],
            $command
        );

        $script = <<<BASH
set -eu
if [ ! -f {$targetPath} ]; then
  echo "{$target['label']} is not available for PHP {$version} on this server."
  exit 1
fi
tmp_dir=\$(mktemp -d)
trap 'rm -rf "\$tmp_dir" /tmp/dply-php-verify.log' EXIT
candidate_path="\$tmp_dir/candidate"
printf '%s' {$this->shellQuote($encoded)} | base64 --decode > "\$candidate_path"
{$command}
BASH;

        return $this->withPrivilege($server, $script);
    }

    /**
     * @param  array{path: string, target: string, version: string, label: string}  $target
     */
    protected function buildReplaceScript(Server $server, array $target, string $content): string
    {
        $targetPath = escapeshellarg($target['path']);
        $encoded = base64_encode($content);

        $script = <<<BASH
set -eu
if [ ! -f {$targetPath} ]; then
  echo "{$target['label']} is not available for PHP {$target['version']} on this server."
  exit 1
fi
tmp_file=\$(mktemp)
trap 'rm -f "\$tmp_file"' EXIT
printf '%s' {$this->shellQuote($encoded)} | base64 --decode > "\$tmp_file"
install -m 0644 "\$tmp_file" {$targetPath}
BASH;

        return $this->withPrivilege($server, $script);
    }

    protected function buildReloadScript(Server $server, string $version): string
    {
        $unit = escapeshellarg('php'.$version.'-fpm');

        $script = <<<BASH
set -eu
if command -v systemctl >/dev/null 2>&1; then
  systemctl reload {$unit} || systemctl restart {$unit}
elif command -v service >/dev/null 2>&1; then
  service {$unit} reload || service {$unit} restart
else
  echo "No supported service manager found to reload PHP-FPM {$version}."
  exit 1
fi
BASH;

        return $this->withPrivilege($server, $script);
    }

    protected function withPrivilege(Server $server, string $script): string
    {
        $wrapped = 'bash -lc '.escapeshellarg($script);
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $wrapped;
        }

        return 'sudo -n '.$wrapped;
    }

    protected function shellQuote(string $value): string
    {
        return escapeshellarg($value);
    }
}

class ServerPhpConfigValidationException extends \RuntimeException
{
    public function __construct(string $message, protected string $validationOutput)
    {
        parent::__construct($message);
    }

    public function validationOutput(): string
    {
        return $this->validationOutput;
    }
}
