<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Models\Server;
use Illuminate\Console\Command;
use Throwable;

/**
 * Install a runtime on a server via mise from the CLI.
 *
 * Mirror of the inline "Install missing runtime" affordance in the
 * site-create form, exposed at the command line for ops and scripted
 * provisioning. Useful when bootstrapping a polyglot host that needs
 * a runtime version mise didn't pre-pin in `meta.runtime_defaults`.
 *
 *   dply:install-runtime <server> <runtime> <version> [--json]
 *
 * <server> matches by ULID, name, or IP — first hit wins. <runtime> is
 * one of the polyglot-five-minus-PHP keys (node / python / ruby / go);
 * php is silently skipped because it uses ondrej/php apt instead of
 * mise (matches MiseInstallScriptBuilder's SUPPORTED_RUNTIMES guard).
 *
 * Exit codes: 0 on success or silent skip; 1 on caller / SSH error.
 */
class InstallRuntimeCommand extends Command
{
    protected $signature = 'dply:install-runtime
        {server : Server ID, name, or IP}
        {runtime : Runtime key (node / python / ruby / go)}
        {version : Version pin (e.g. 22.7.0, 3.12, 3.3.4, 1.22)}
        {--json : Output the result as JSON}';

    protected $description = 'Install a runtime version on a server via mise.';

    public function handle(InstallRuntimeOnServer $action): int
    {
        $server = $this->resolveServer((string) $this->argument('server'));
        if ($server === null) {
            $this->error('Server not found: '.$this->argument('server'));

            return self::FAILURE;
        }

        $runtime = (string) $this->argument('runtime');
        $version = (string) $this->argument('version');

        try {
            $result = $action->execute($server, $runtime, $version);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'server_id' => $server->id,
                    'runtime' => $runtime,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $result['installed'],
                'server_id' => $server->id,
                'runtime' => $result['runtime'],
                'version' => $result['version'],
                'output' => $result['output'],
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (! $result['installed']) {
            $this->warn(sprintf(
                'Skipped — %s isn\'t mise-managed (PHP uses ondrej/php apt; unknown runtimes are no-ops).',
                $runtime,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Installed %s@%s on %s.',
            $result['runtime'],
            $result['version'],
            $server->name,
        ));

        if ($result['output'] !== '') {
            $this->newLine();
            $this->line('<fg=cyan>Output:</>');
            $this->line($result['output']);
        }

        return self::SUCCESS;
    }

    private function resolveServer(string $needle): ?Server
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('ip_address', $needle)
            ->first();
    }
}
