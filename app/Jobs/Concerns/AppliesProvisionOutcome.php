<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Actions\Servers\SeedProvisionedEnginesForServer;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\InstallMetricsAgentJob;
use App\Jobs\RefreshServerInventoryJob;
use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerCredentialShare;
use App\Models\UserSshKey;
use App\Notifications\RedisServerProvisionedNotification;
use App\Notifications\ServerProvisionFailedNotification;
use App\Notifications\ServerProvisionedCredentialsNotification;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerProvisionCommandBuilder;
use App\Support\Servers\ProvisionPipelineLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait AppliesProvisionOutcome
{


    /**
     * Apply provision outcome to the server (setup_status, optional deploy ssh_user).
     * On transient failures, schedules an automatic retry with backoff up to MAX_AUTO_RETRY_ATTEMPTS.
     */
    public static function applyProvisionOutcomeToServer(Server $server, bool $success): void
    {
        $server->refresh();

        if ($success) {
            $updates = [
                'setup_status' => Server::SETUP_STATUS_DONE,
            ];
            if ($server->hasDedicatedOperationalSshPrivateKey()) {
                $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
                if ($deployUser !== '' && $deployUser !== 'root'
                    && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser)) {
                    $updates['ssh_user'] = $deployUser;
                }
            }
            // Clear any prior auto-retry markers on success.
            $meta = $server->meta ?? [];
            unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);
            $updates['meta'] = $meta;
            $server->update($updates);

            // Email server credentials to the creator IF the org has the
            // toggle on. Opt-in only: most operators don't want
            // connection blocks landing in mailboxes by default. The
            // email itself only carries host/port/user — the SSH key
            // download stays gated behind an authenticated dashboard
            // session (see ServerProvisionedCredentialsNotification).
            $organization = $server->organization;
            $creator = $server->user;
            if ($organization
                && $organization->email_server_credentials_enabled
                && $creator
                && filled($creator->email)
            ) {
                $creator->notify(new ServerProvisionedCredentialsNotification($server->fresh() ?? $server));
            }

            // Dedicated redis servers get a tailored "your cache is live" email
            // with a reveal-once link to the AUTH password (never emailed
            // directly). Always sent for redis hosts — this is the feature the
            // operator opted into when picking the redis profile. Idempotent
            // via a meta flag so retries don't re-issue links.
            $redisServer = $server->fresh() ?? $server;
            if ($creator
                && filled($creator->email)
                && $redisServer->isRedisServer()
                && blank(data_get($redisServer->meta, 'redis_provisioned_email_sent_at'))
            ) {
                $share = ServerCredentialShare::issue($redisServer, $creator);
                $creator->notify(new RedisServerProvisionedNotification($redisServer, $share->token));

                $redisMeta = $redisServer->meta ?? [];
                $redisMeta['redis_provisioned_email_sent_at'] = now()->toIso8601String();
                $redisServer->update(['meta' => $redisMeta]);
            }

            // Kick off insights immediately so the workspace lands with a
            // populated heartbeat / metrics-missing baseline instead of an
            // empty state that requires the operator to hit "Refresh"
            // before anything appears. Job no-ops if the server isn't
            // ready yet, so the dispatch is safe even on edge timing.
            if (config('insights.queue_after_install', true) && $server->isVmHost()) {
                RunServerInsightsJob::dispatch($server->id);
            }

            // Fire an immediate health check so the workspace Overview's
            // Health tile flips from "Not checked yet" to a real result
            // within seconds of provisioning finishing, instead of
            // waiting up to 5 minutes for the recurring scheduler at
            // bootstrap/app.php to catch up. The job is idempotent —
            // worst case the scheduler re-checks 5 minutes later
            // anyway and overwrites with fresh data.
            if (! empty($server->ip_address) && $server->isVmHost()) {
                CheckServerHealthJob::dispatch($server);
            }

            // Wire up the metrics push pipeline. Two paths converge here
            // depending on whether the inline metrics step ran during
            // the bash provision:
            //   - inline=true (default) → bash already installed Python +
            //     the snapshot script. syncPushArtifactsAfterInstall just
            //     writes the env file + crontab block, so the server starts
            //     pushing metrics the moment the journey reads "ready".
            //   - inline=false → bash skipped the agent. Dispatch
            //     InstallMetricsAgentJob to SSH the install bash on a
            //     separate connection AFTER the journey reads "ready". That
            //     job dispatches the env/cron deploy on success, so the
            //     post-install state ends up identical either way; the user
            //     just gets ~30–60s back on the wall-clock at the cost of
            //     ~1 minute with no monitoring.
            if ((bool) config('server_provision.install_metrics_agent', true)
                && ! empty($server->ip_address)
                && $server->isVmHost()
            ) {
                if ((bool) config('server_provision.install_metrics_agent_inline', false)) {
                    app(ServerMetricsGuestPushService::class)->syncPushArtifactsAfterInstall($server);
                } else {
                    InstallMetricsAgentJob::dispatch((string) $server->id)
                        ->delay(now()->addSeconds(15));
                }
            }

            // Mirror the bash provision script's UFW defaults (SSH on
            // the server's ssh_port + HTTP/HTTPS for VM hosts) into
            // server_firewall_rules so the dashboard reflects what's
            // actually on the host. Idempotent — the applicator dedupes
            // by (port, protocol, source, action) so reruns are no-ops.
            try {
                app(FirewallRuleTemplateApplicator::class)
                    ->seedDefaultsForServer($server->fresh() ?? $server, $server->user);
            } catch (\Throwable $e) {
                // Seeding is best-effort: a failure here shouldn't fail
                // the whole provision job. Log and move on; the
                // workspace's "Apply" button can recreate rules later.
                ProvisionPipelineLog::warning('server.provision.firewall_seed_failed', $server, [
                    'error' => $e->getMessage(),
                ]);
            }

            // Populate the System services list on first connect.
            // Without this, operators land on Settings → Services to
            // a completely empty card and have to click Sync now to
            // see anything. Delay 30s so it doesn't compete with the
            // metrics install + insights run for SSH bandwidth.
            if (! empty($server->ip_address) && $server->isVmHost()) {
                SyncServerSystemdServicesJob::dispatch((string) $server->id)
                    ->delay(now()->addSeconds(30));
            }

            // Same idea for System users — without this seed, the
            // Settings → System users page is empty after provision
            // (no rows in server_system_users) and the operator has to
            // click "Sync now" before the deploy user (`dply`) even
            // appears. Sync is unique-keyed against create/remove, so
            // a manual click later is still safe. Stagger 35s after the
            // services sync (lighter SSH, lone `getent passwd` exec).
            if (! empty($server->ip_address) && $server->isVmHost()) {
                SyncServerSystemUsersJob::dispatch((string) $server->id)
                    ->delay(now()->addSeconds(35));
            }

            // The provision command builder writes the creator's
            // provision-flagged profile keys directly into
            // /home/<deploy>/.ssh/authorized_keys at bootstrap time
            // (see ServerProvisionCommandBuilder::dplyDeployUserBootstrap).
            // Mirror them into the panel here so the workspace SSH Keys
            // page reflects reality from day zero — without this, the
            // first user-initiated Sync would treat the bootstrap-installed
            // keys as drift and remove them on the next push.
            try {
                static::hydrateAuthorizedKeyPanelRowsFromCreator($server);
            } catch (\Throwable $e) {
                ProvisionPipelineLog::warning('server.provision.authorized_key_panel_hydration_failed', $server, [
                    'error' => $e->getMessage(),
                ]);
            }

            // Capture the full inventory/manage snapshot (package versions,
            // service state, kernel reboot-required, unattended-upgrades
            // status, etc.) so the Manage tab lands populated instead of
            // showing "No state data yet · Never refreshed". Delay 45s so
            // it runs after the metrics agent install (15s) and systemd
            // sync (30s) — they share SSH bandwidth.
            if (! empty($server->ip_address) && $server->isVmHost()) {
                RefreshServerInventoryJob::dispatch((string) $server->id)
                    ->delay(now()->addSeconds(45));
            }

            // Mirror meta['cache_service'] and meta['database'] into the
            // workspace tables so the Caches / Databases pages have a row
            // to render. The provision shell scripts already installed the
            // packages and started the services; this is the missing data
            // bridge. Idempotent — only inserts when the row is absent.
            try {
                app(SeedProvisionedEnginesForServer::class)
                    ->execute($server->fresh() ?? $server);
            } catch (\Throwable $e) {
                ProvisionPipelineLog::warning('server.provision.engine_seed_failed', $server, [
                    'error' => $e->getMessage(),
                ]);
            }

            // Fan out provision-success to the org's configured operational
            // channels (Slack/Discord/Teams/etc) plus publish to the in-app
            // inbox. Always-send on the channel path — success fan-outs of
            // "<server> is ready" aren't noisy enough to warrant per-server
            // opt-in subscriptions. The creator email stays on its existing
            // `email_server_credentials_enabled` opt-in (handled above) so the
            // SSH credentials block is still gated.
            static::dispatchProvisionEvent(
                $server->fresh() ?? $server,
                eventKey: 'server.provisioned',
                channelSubject: sprintf('[dply] Server is ready: %s', $server->name ?: $server->id),
                inboxTitle: sprintf('Server "%s" is ready', $server->name ?: $server->id),
                inboxBody: 'Provisioning finished. Open the server overview to start adding sites or connect via SSH.',
                actionUrl: URL::route('servers.overview', $server),
                actionLabel: 'Open server',
                bodyLines: static::successBodyLines($server),
            );

            return;
        }

        if (static::tryScheduleAutoRetry($server)) {
            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);
        $server->update([
            'setup_status' => Server::SETUP_STATUS_FAILED,
            'meta' => $meta,
        ]);

        // Always alert the creator — silent failures are worse than a few extra
        // emails. The UI also shows a "Setup failed" chip on the index card
        // (see resources/views/livewire/servers/index.blade.php), but operators
        // not actively watching the dashboard need a push.
        $creator = $server->user;
        $excerpt = static::extractLastProvisionError($server);
        if ($creator && filled($creator->email)) {
            try {
                $creator->notify(new ServerProvisionFailedNotification($server->fresh() ?? $server, $excerpt));
            } catch (\Throwable $e) {
                ProvisionPipelineLog::warning('server.provision.failure_email_send_failed', $server, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $failureBodyLines = [
            sprintf('Provider: %s', $server->provider->label()),
            sprintf('Address: %s', $server->ip_address ?: '—'),
        ];
        if (is_string($excerpt) && trim($excerpt) !== '') {
            $failureBodyLines[] = 'Last error: '.Str::limit(trim($excerpt), 400);
        }

        static::dispatchProvisionEvent(
            $server->fresh() ?? $server,
            eventKey: 'server.provision_failed',
            channelSubject: sprintf('[dply] Server provisioning failed: %s', $server->name ?: $server->id),
            inboxTitle: sprintf('Server "%s" failed to provision', $server->name ?: $server->id),
            inboxBody: 'Provisioning stopped before finishing. Open the journey to see the failing step and retry.',
            actionUrl: URL::route('servers.journey', $server),
            actionLabel: 'Open journey',
            bodyLines: $failureBodyLines,
        );
    }

    /**
     * Common path for provision-{success,failure} fan-out. Sends an operational
     * message to every NotificationChannel attached to the server's organization
     * (always-on; subscriptions are intentionally bypassed) then publishes a
     * NotificationEvent so the in-app inbox + any subscribed Slack/Discord
     * channels also see it. The publisher call passes the just-dispatched channel
     * IDs as `excludeChannelIds` so subscribed channels don't receive a second
     * copy via the routing pipe.
     *
     * @param  list<string>  $bodyLines  Pre-formatted lines for the chat-channel
     *                                   body (joined with \n). Inbox uses the
     *                                   shorter `$inboxBody`.
     */
    protected static function dispatchProvisionEvent(
        Server $server,
        string $eventKey,
        string $channelSubject,
        string $inboxTitle,
        string $inboxBody,
        string $actionUrl,
        string $actionLabel,
        array $bodyLines,
    ): void {
        $sentChannelIds = [];
        try {
            $organization = $server->organization;
            if ($organization !== null) {
                $organization->loadMissing('notificationChannels');
                $body = implode("\n", $bodyLines);
                foreach ($organization->notificationChannels as $channel) {
                    $channel->sendOperationalMessage($channelSubject, $body, $actionUrl, $actionLabel);
                    $sentChannelIds[] = (string) $channel->id;
                }
            }
        } catch (\Throwable $e) {
            ProvisionPipelineLog::warning('server.provision.channel_dispatch_failed', $server, [
                'event_key' => $eventKey,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(NotificationPublisher::class)->publish(
                eventKey: $eventKey,
                subject: $server,
                title: $inboxTitle,
                body: $inboxBody,
                url: $actionUrl,
                metadata: [
                    'server_name' => $server->name,
                    'ip' => $server->ip_address,
                ],
                excludeChannelIds: $sentChannelIds,
            );
        } catch (\Throwable $e) {
            ProvisionPipelineLog::warning('server.provision.event_publish_failed', $server, [
                'event_key' => $eventKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format the wizard's stack choices (php_version, database, cache_service,
     * webserver) into a short list of "Label: value" lines for the success
     * channel body. Skips slots the operator left empty or set to 'none'.
     *
     * @return list<string>
     */
    protected static function successBodyLines(Server $server): array
    {
        $lines = [
            sprintf('Provider: %s', $server->provider->label()),
            sprintf('Address: %s', $server->ip_address ?: '—'),
        ];

        $meta = $server->meta ?? [];
        $stackBits = [];
        $php = trim((string) ($meta['php_version'] ?? ''));
        if ($php !== '' && $php !== 'none') {
            $stackBits[] = 'PHP '.$php;
        }
        $db = trim((string) ($meta['database'] ?? ''));
        if ($db !== '' && $db !== 'none') {
            $stackBits[] = self::humanizeDatabase($db);
        }
        $cache = trim((string) ($meta['cache_service'] ?? ''));
        if ($cache !== '' && $cache !== 'none') {
            $stackBits[] = ucfirst($cache);
        }
        $webserver = trim((string) ($meta['webserver'] ?? ''));
        if ($webserver !== '' && $webserver !== 'none') {
            $stackBits[] = ucfirst($webserver);
        }

        if ($stackBits !== []) {
            $lines[] = 'Stack: '.implode(' · ', $stackBits);
        }

        return $lines;
    }

    /**
     * Map raw database meta values (postgres18, mariadb1011, sqlite3, ...) to
     * a more chat-friendly label. Falls back to ucfirst when the pattern isn't
     * recognised so new engines render legibly without code changes here.
     */
    protected static function humanizeDatabase(string $engine): string
    {
        if (preg_match('/^postgres(\d+)$/', $engine, $m)) {
            return 'PostgreSQL '.$m[1];
        }
        if (preg_match('/^mysql(\d)(\d)$/', $engine, $m)) {
            return 'MySQL '.$m[1].'.'.$m[2];
        }
        if (preg_match('/^mariadb(\d{2,4})$/', $engine, $m)) {
            $v = $m[1];
            if (strlen($v) === 2) {
                return 'MariaDB '.$v[0].'.'.$v[1];
            }
            if (strlen($v) === 4) {
                return 'MariaDB '.substr($v, 0, 2).'.'.substr($v, 2);
            }

            return 'MariaDB '.$v;
        }
        if (preg_match('/^sqlite(\d+)$/', $engine, $m)) {
            return 'SQLite '.$m[1];
        }

        return ucfirst($engine);
    }

    /**
     * Pull a short snippet of the most recent failure from the provision
     * step snapshots stored in meta so the failure email can carry context
     * without making the recipient open the journey to know what broke.
     * Returns null when no usable error string is available.
     */
    protected static function extractLastProvisionError(Server $server): ?string
    {
        $meta = $server->meta ?? [];
        $snapshots = $meta['provision_step_snapshots'] ?? null;
        if (! is_array($snapshots) || $snapshots === []) {
            return null;
        }

        // Walk the snapshots in insertion order and keep the last non-empty
        // output we see — the failing step is normally the most recent one
        // recorded, and its output contains the apt/ssh error message.
        $lastOutput = null;
        foreach ($snapshots as $snap) {
            if (! is_array($snap)) {
                continue;
            }
            $output = trim((string) ($snap['output'] ?? ''));
            if ($output !== '') {
                $lastOutput = $output;
            }
        }

        return $lastOutput;
    }

    /**
     * Pre-populate ServerAuthorizedKey panel rows for the creator's profile
     * keys that opted into new-server provisioning. These keys are written
     * directly into the deploy user's authorized_keys by the bootstrap script
     * (see {@see ServerProvisionCommandBuilder::dplyDeployUserBootstrap()});
     * mirroring them as panel rows here keeps the workspace SSH Keys page
     * truthful and prevents the next user-initiated Sync from treating the
     * bootstrap-installed keys as drift and removing them.
     *
     * Idempotent via updateOrCreate — re-applying a provision outcome (manual
     * retry, etc.) won't duplicate rows.
     */
    protected static function hydrateAuthorizedKeyPanelRowsFromCreator(Server $server): void
    {
        $creator = $server->user;
        if ($creator === null) {
            return;
        }

        $rows = $creator->sshKeys()
            ->where('provision_on_new_servers', true)
            ->orderBy('name')
            ->get(['id', 'name', 'public_key']);

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $userKey) {
            $pk = trim((string) $userKey->public_key);
            if ($pk === '' || ! UserSshKey::publicKeyLooksValid($pk)) {
                continue;
            }

            // target_linux_user = '' means "the server's login user" by the
            // synchronizer's convention (see ServerAuthorizedKeysSynchronizer).
            // The deploy user is ssh_user at this point because
            // applyProvisionOutcomeToServer already flipped it.
            ServerAuthorizedKey::query()->updateOrCreate(
                [
                    'server_id' => $server->id,
                    'managed_key_type' => UserSshKey::class,
                    'managed_key_id' => $userKey->id,
                    'target_linux_user' => '',
                ],
                [
                    'name' => (string) $userKey->name,
                    'public_key' => $pk,
                    'synced_at' => now(),
                ]
            );
        }
    }
}
