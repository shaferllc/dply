<?php

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Detects whether the guest has python3 for metrics collection and stores the result on {@see Server::$meta}.
 */
class ServerMonitoringProbe
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * Probe over SSH and persist monitoring_* keys on the server record.
     *
     * @return array{reachable: bool, python_installed: ?bool, error: ?string}
     */
    public function probeAndStore(Server $server): array
    {
        $server = $server->fresh();
        $result = $this->probe($server);
        $this->persistMeta($server, $result);

        return $result;
    }

    /**
     * @return array{reachable: bool, python_installed: ?bool, error: ?string}
     */
    public function probe(Server $server): array
    {
        $inline = <<<'BASH'
if command -v python3 >/dev/null 2>&1; then
  echo "DPLY_PY_OK"
else
  echo "DPLY_PY_MISSING"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash(
                $server,
                'monitoring-probe-python3',
                $inline,
                30,
                false,
            );
            $buffer = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));

            if (str_contains($buffer, 'DPLY_PY_OK')) {
                return [
                    'reachable' => true,
                    'python_installed' => true,
                    'error' => null,
                ];
            }

            if (str_contains($buffer, 'DPLY_PY_MISSING')) {
                return [
                    'reachable' => true,
                    'python_installed' => false,
                    'error' => null,
                ];
            }

            return [
                'reachable' => true,
                'python_installed' => false,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'python_installed' => null,
                'error' => Str::limit($e->getMessage(), 500),
            ];
        }
    }

    /**
     * @param  array{reachable: bool, python_installed: ?bool, error: ?string}  $result
     */
    protected function persistMeta(Server $server, array $result): void
    {
        $meta = $server->meta ?? [];
        $meta['monitoring_probe_at'] = now()->toIso8601String();
        $meta['monitoring_ssh_reachable'] = $result['reachable'];

        if ($result['reachable']) {
            $meta['monitoring_python_installed'] = (bool) $result['python_installed'];
            unset($meta['monitoring_probe_error']);
        } else {
            unset($meta['monitoring_python_installed']);
            $meta['monitoring_probe_error'] = $result['error'] ?? __('Could not connect over SSH.');
        }

        $server->update(['meta' => $meta]);
    }
}
