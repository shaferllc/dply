<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerRemoteAccessEvent;
use Illuminate\Support\Str;

/**
 * Records when Dply SSHes into a server on behalf of jobs and console actions.
 */
final class ServerRemoteAccessLogger
{
    public function touch(Server $server, string $linuxUser, string $credentialRole): void
    {
        if (! $this->enabled() || ! app()->bound(ServerRemoteAccessContext::class)) {
            return;
        }

        /** @var ServerRemoteAccessContext $context */
        $context = app(ServerRemoteAccessContext::class);
        $serverId = (string) $server->id;

        if ($context->eventIdFor($serverId) !== null) {
            return;
        }

        $meta = array_filter([
            'console_action_id' => $context->consoleActionId,
            'job_uuid' => $context->jobUuid,
        ]);

        $event = ServerRemoteAccessEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $context->userId,
            'linux_user' => $linuxUser,
            'credential_role' => $credentialRole,
            'source' => $context->source,
            'label' => $context->label,
            'started_at' => now(),
            'command_count' => 0,
            'failed' => false,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        $context->rememberEvent($serverId, (string) $event->id);
    }

    public function recordCommand(Server $server, string $command): void
    {
        if (! $this->enabled() || ! app()->bound(ServerRemoteAccessContext::class)) {
            return;
        }

        /** @var ServerRemoteAccessContext $context */
        $context = app(ServerRemoteAccessContext::class);
        $eventId = $context->eventIdFor((string) $server->id);
        if ($eventId === null) {
            return;
        }

        $previewMax = max(40, (int) config('server_ssh_access.remote_access_command_preview_max', 120));
        $preview = Str::squish($command);
        if (mb_strlen($preview) > $previewMax) {
            $preview = mb_substr($preview, 0, $previewMax).'…';
        }

        $event = ServerRemoteAccessEvent::query()->find($eventId);
        if ($event === null) {
            return;
        }

        $meta = is_array($event->meta) ? $event->meta : [];
        $meta['last_command'] = $preview;

        $event->forceFill([
            'command_count' => $event->command_count + 1,
            'meta' => $meta,
        ])->save();
    }

    public function finishContext(): void
    {
        if (! $this->enabled() || ! app()->bound(ServerRemoteAccessContext::class)) {
            return;
        }

        /** @var ServerRemoteAccessContext $context */
        $context = app(ServerRemoteAccessContext::class);

        if ($context->eventIds() === []) {
            app()->forgetInstance(ServerRemoteAccessContext::class);

            return;
        }

        ServerRemoteAccessEvent::query()
            ->whereIn('id', $context->eventIds())
            ->whereNull('finished_at')
            ->update([
                'finished_at' => now(),
                'failed' => $context->failed,
                'updated_at' => now(),
            ]);

        app()->forgetInstance(ServerRemoteAccessContext::class);
    }

    private function enabled(): bool
    {
        return (bool) config('server_ssh_access.log_remote_access', true);
    }
}
