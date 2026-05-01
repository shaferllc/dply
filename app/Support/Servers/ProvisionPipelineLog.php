<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Structured context for server provisioning / setup journey logging (grep: server.provision).
 */
final class ProvisionPipelineLog
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function context(Server $server, array $extra = []): array
    {
        $meta = $server->meta ?? [];

        return array_merge([
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'server_status' => $server->status,
            'setup_status' => $server->setup_status,
            'provider' => $server->provider?->value,
            'ip' => $server->ip_address,
            'ssh_port' => $server->ssh_port,
            'provision_task_id' => $meta['provision_task_id'] ?? null,
            'provision_run_id' => $meta['provision_run_id'] ?? null,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function info(string $message, Server $server, array $extra = []): void
    {
        Log::info($message, self::context($server, $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function debug(string $message, Server $server, array $extra = []): void
    {
        Log::debug($message, self::context($server, $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function warning(string $message, Server $server, array $extra = []): void
    {
        Log::warning($message, self::context($server, $extra));
    }
}
