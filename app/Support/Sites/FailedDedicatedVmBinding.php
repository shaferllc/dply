<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Actions\Servers\DeleteServerAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\DedicatedResourceProvisionFailedNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a dedicated Redis/DB VM never comes up: mark the binding failed, destroy
 * the orphan box so it does not keep billing, and tell the operator.
 */
final class FailedDedicatedVmBinding
{
    public function settle(SiteBinding $binding, string $error, ?Server $server): void
    {
        $composed = $this->composeError($error, $server);
        $config = is_array($binding->config) ? $binding->config : [];
        unset($config['cache_vm_server_id'], $config['db_vm_server_id']);

        $binding->forceFill([
            'status' => SiteBinding::STATUS_ERROR,
            'last_error' => $composed,
            'target_id' => null,
            'config' => $config,
        ])->save();

        $site = $binding->site;

        if ($server instanceof Server) {
            try {
                app(DeleteServerAction::class)->execute(
                    $server,
                    $server->user,
                    ['reason' => 'dedicated_binding_provision_failed'],
                );
            } catch (Throwable $e) {
                Log::warning('dedicated_vm.teardown_failed', [
                    'server_id' => $server->id,
                    'binding_id' => $binding->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($site instanceof Site) {
            $this->notify($site, $binding, $composed);
        }
    }

    private function composeError(string $error, ?Server $server): string
    {
        if (! $server instanceof Server) {
            return $error;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $provisionError = is_array($meta['provision_error'] ?? null) ? $meta['provision_error'] : [];
        $serverMessage = trim((string) ($provisionError['message'] ?? ''));
        if ($serverMessage === '' || str_contains($error, $serverMessage)) {
            return $error;
        }

        return $error.' — '.$serverMessage;
    }

    private function notify(Site $site, SiteBinding $binding, string $error): void
    {
        $label = match ($binding->type) {
            'redis' => __('Redis'),
            default => __('Database'),
        };

        $recipients = collect([$site->user, $site->server?->user])
            ->filter(static fn (mixed $user): bool => $user instanceof User && filled($user->email))
            ->unique('id');

        foreach ($recipients as $user) {
            try {
                $user->notify(new DedicatedResourceProvisionFailedNotification($site, $label, $error));
            } catch (Throwable $e) {
                Log::warning('dedicated_vm.notify_failed', [
                    'site_id' => $site->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $server = $site->server;
            if ($server instanceof Server) {
                app(NotificationPublisher::class)->publish(
                    'site.errors.operation_failed',
                    $site,
                    title: __(':resource provision failed', ['resource' => $label]),
                    body: $error,
                    url: route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'resources']),
                    additionalRecipientUsers: $recipients->all(),
                );
            }
        } catch (Throwable $e) {
            Log::warning('dedicated_vm.publish_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
