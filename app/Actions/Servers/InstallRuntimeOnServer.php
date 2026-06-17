<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Services\Servers\MiseInstallScriptBuilder;
use App\Services\SshConnection;
use Closure;

/**
 * Install (or upgrade to) a specific runtime version on a server via mise.
 *
 * Powers the URL-first form's "Install missing runtime" affordance: if the
 * user pastes a Ruby repo for a server that doesn't yet have Ruby in its
 * `meta.runtime_defaults`, the form offers to install it now. Per the
 * strategy memo: "Inline 'install missing runtime' action ships in v1 …
 * the polyglot pitch made tangible at the site-create moment."
 *
 * Mechanics:
 *   - Runs `mise use --global <runtime>@<version>` as the deploy user.
 *     mise installs the requested version if it's not already present
 *     and pins it as the global default for that runtime.
 *   - Updates `meta.runtime_defaults` so future site-creates know the
 *     runtime is available without re-probing.
 *   - Skips PHP (which uses ondrej/php apt) and unknown runtimes
 *     silently — same shape as MiseInstallScriptBuilder.
 *
 * Returns the captured SSH output so the UI can surface mise's progress
 * lines if installation took a while.
 */
class InstallRuntimeOnServer
{
    public function __construct(
        private MiseInstallScriptBuilder $builder,
    ) {}

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory  test seam
     * @return array{installed: bool, runtime: string, version: string, output: string}
     */
    public function execute(
        Server $server,
        string $runtime,
        string $version,
        ?Closure $shellFactory = null,
    ): array {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $deployUser = (string) ($server->ssh_user ?? config('server_provision.deploy_ssh_user', 'dply'));
        $lines = $this->builder->installRuntimeForUserLines($deployUser, $runtime, $version);

        if ($lines === []) {
            // PHP / unknown runtime / blank version — consistent with the
            // builder's silent-skip behavior. Caller treats this as a
            // non-error: the runtime is either out of scope or already
            // managed (PHP via apt).
            return [
                'installed' => false,
                'runtime' => $runtime,
                'version' => $version,
                'output' => '',
            ];
        }

        $shell = $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
        $output = '';
        foreach ($lines as $line) {
            // mise installs can take 30–120s for source-built tools (Ruby
            // and Python on small droplets). Generous timeout per line.
            $output .= $shell->exec($line, 300);
        }

        $this->recordRuntimeDefault($server, $runtime, $version);

        return [
            'installed' => true,
            'runtime' => $runtime,
            'version' => $version,
            'output' => $output,
        ];
    }

    private function recordRuntimeDefault(Server $server, string $runtime, string $version): void
    {
        $meta = $server->meta;
        $defaults = is_array($meta['runtime_defaults'] ?? null) ? $meta['runtime_defaults'] : [];
        $defaults[$runtime] = $version;
        $meta['runtime_defaults'] = $defaults;
        $server->meta = $meta;
        $server->save();
    }
}
