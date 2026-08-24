<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerResizeNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Announces the three moments of a server resize to the organization.
 *
 * A resize is the rare dply operation that knowingly takes production offline,
 * so "started" is not a courtesy — it is the only warning anyone who did not
 * click the button gets. The site list is resolved from the server at send
 * time and carried in the event metadata, so the mail can name exactly what is
 * going down rather than saying "some sites".
 */
class ServerResizeNotificationDispatcher
{
    public function __construct(
        private NotificationPublisher $publisher,
    ) {}

    public function started(Server $server, string $fromSize, string $toSize, bool $powerCycle, ?User $actor = null): void
    {
        $this->dispatch($server, 'started', $fromSize, $toSize, $powerCycle, $actor);
    }

    public function completed(Server $server, string $fromSize, string $toSize, ?User $actor = null): void
    {
        $this->dispatch($server, 'completed', $fromSize, $toSize, true, $actor);
    }

    public function failed(Server $server, string $fromSize, string $toSize, string $error, ?User $actor = null): void
    {
        $this->dispatch($server, 'failed', $fromSize, $toSize, true, $actor, $error);
    }

    private function dispatch(
        Server $server,
        string $phase,
        string $fromSize,
        string $toSize,
        bool $powerCycle,
        ?User $actor,
        ?string $error = null,
    ): void {
        $org = $server->organization;
        if ($org === null) {
            return;
        }

        // Owners and admins: the people who can act on a machine being down.
        // Deployers deliberately excluded — they cannot resize or roll back.
        $users = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($users->isEmpty()) {
            return;
        }

        $siteNames = $server->sites()->orderBy('name')->pluck('name')->all();

        $title = match ($phase) {
            'completed' => '['.config('app.name').'] '.$server->name.' resize finished',
            'failed' => '['.config('app.name').'] '.$server->name.' resize FAILED',
            default => '['.config('app.name').'] '.$server->name.' is resizing — going offline',
        };

        $body = match ($phase) {
            'completed' => 'Now running '.$toSize.' (was '.$fromSize.').',
            'failed' => 'Attempted '.$fromSize.' → '.$toSize.'. '.($error ?? ''),
            default => $fromSize.' → '.$toSize.'. '.count($siteNames).' site(s) affected.',
        };

        $event = $this->publisher->publish(
            eventKey: 'server.resize.'.$phase,
            subject: $server,
            title: $title,
            body: trim($body),
            url: route('servers.show', $server, absolute: true),
            metadata: [
                'phase' => $phase,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'organization_name' => $org->name,
                'from_size' => $fromSize,
                'to_size' => $toSize,
                'power_cycle' => $powerCycle,
                'site_count' => count($siteNames),
                'site_names' => $siteNames,
                'error' => $error,
            ],
            actor: $actor,
            recipientUsers: $users->pluck('id')->all(),
        );

        Notification::send($users, new ServerResizeNotification($event));
    }
}
