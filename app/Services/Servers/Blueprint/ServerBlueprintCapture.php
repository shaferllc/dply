<?php

declare(strict_types=1);

namespace App\Services\Servers\Blueprint;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Models\SupervisorProgram;
use App\Support\Servers\InstalledStack;

/**
 * Capture a reconciled stack snapshot from a ready VM for org blueprint storage.
 */
final class ServerBlueprintCapture
{
    /**
     * @return array{
     *     version: int,
     *     stack: array<string, mixed>,
     *     server_role: string,
     *     install_profile: string,
     *     runtime_defaults: array<string, string>,
     *     firewall_rules: list<array<string, mixed>>,
     *     supervisor_programs: list<array<string, mixed>>,
     * }
     */
    /** @return array<string, mixed> */
    public function fromServer(Server $server): array
    {
        $server->loadMissing(['firewallRules', 'supervisorPrograms']);

        $meta = is_array($server->meta) ? $server->meta : [];
        $stack = InstalledStack::fromMeta($server);

        $installProfile = (string) ($meta['install_profile'] ?? '');
        if ($installProfile === '' && is_string($meta['preset'] ?? null)) {
            $installProfile = (string) $meta['preset'];
        }

        $runtimeDefaults = $meta['runtime_defaults'] ?? [];
        if (! is_array($runtimeDefaults)) {
            $runtimeDefaults = [];
        }

        /** @var array $normalizedRuntimes */
        $normalizedRuntimes = [];
        foreach ($runtimeDefaults as $runtime => $version) {
            if (is_string($runtime) && is_string($version) && $version !== '') {
                $normalizedRuntimes[$runtime] = $version;
            }
        }

        return [
            'version' => (int) config('server_blueprint.snapshot_version', 1),
            'stack' => $stack->toArray(),
            'server_role' => (string) ($meta['server_role'] ?? 'application'),
            'install_profile' => $installProfile,
            'runtime_defaults' => $normalizedRuntimes,
            'firewall_rules' => $this->captureFirewallRules($server),
            'supervisor_programs' => $this->captureSupervisorPrograms($server),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function captureFirewallRules(Server $server): array
    {
        return $server->firewallRules
            ->filter(fn (ServerFirewallRule $rule): bool => $rule->site_id === null)
            ->sortBy('sort_order')
            ->values()
            ->map(fn (ServerFirewallRule $rule): array => [
                'name' => $rule->name,
                'profile' => $rule->profile,
                'port' => $rule->port,
                'protocol' => $rule->protocol,
                'source' => $rule->source,
                'action' => $rule->action,
                'enabled' => (bool) $rule->enabled,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function captureSupervisorPrograms(Server $server): array
    {
        return $server->supervisorPrograms
            ->filter(fn (SupervisorProgram $program): bool => $program->site_id === null && $program->is_active)
            ->values()
            ->map(fn (SupervisorProgram $program): array => [
                'slug' => $program->slug,
                'program_type' => $program->program_type,
                'command' => $program->command,
                'directory' => $program->directory,
                'user' => $program->user,
                'numprocs' => $program->numprocs,
                'autorestart' => $program->autorestart,
            ])
            ->all();
    }
}
