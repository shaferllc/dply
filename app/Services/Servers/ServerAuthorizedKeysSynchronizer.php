<?php

namespace App\Services\Servers;

use App\Events\Servers\ServerAuthorizedKeysSynced;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class ServerAuthorizedKeysSynchronizer
{
    public const META_SYNCED_LINUX_USERS_KEY = 'authorized_keys_synced_linux_users';

    /**
     * Optional output callback set by callers that want to stream the SSH process output as it's
     * produced (e.g. {@see SyncAuthorizedKeysJob} writing chunks into the application
     * cache so the workspace banner can render a live tail). When null, the synchronizer falls
     * back to the buffered `runScript` path it always used.
     *
     * @var (callable(string, string): void)|null
     */
    protected $outputCallback = null;

    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
        protected ServerAuthorizedKeysAuditLogger $auditLogger,
        protected ServerAuthorizedKeysHealthCheck $healthCheck,
    ) {}

    /**
     * Register a callback that receives every stdout/stderr chunk emitted by the SSH scripts
     * for this sync run. Returning a fluent reference so callers can chain
     * `$sync->withOutputCallback($cb)->sync(...)`.
     *
     * @param  callable(string $type, string $chunk): void  $callback
     */
    public function withOutputCallback(callable $callback): static
    {
        $this->outputCallback = $callback;

        return $this;
    }

    public function sync(Server $server, ?User $initiatedBy = null, ?string $ipAddress = null): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $disableKey = config('server_ssh_keys.meta_disable_sync_key');
        if (data_get($server->meta, $disableKey)) {
            $this->auditLogger->record(
                $server,
                ServerSshKeyAuditEvent::EVENT_SYNC_BLOCKED,
                ['reason' => 'disabled_in_server_meta'],
                $initiatedBy,
                $ipAddress
            );
            throw new \RuntimeException(__('SSH authorized_keys sync is disabled for this server.'));
        }

        $server->loadMissing('authorizedKeys');
        $connectionUser = (string) $server->ssh_user;

        /** @var Collection<string, Collection<int, ServerAuthorizedKey>> $groups */
        $groups = $server->authorizedKeys->groupBy(function (ServerAuthorizedKey $row) use ($server) {
            return $this->normalizedTargetUser($server, (string) ($row->target_linux_user ?? ''));
        });

        $targets = $this->resolveSyncTargets($server, $groups);

        $chunks = [];
        foreach ($targets as $targetUser) {
            $rows = $groups->get($targetUser) ?? collect();
            $chunks[] = $this->writeAuthorizedKeysForTarget($server, $connectionUser, $targetUser, $rows);
        }

        foreach ($server->authorizedKeys as $row) {
            $row->update(['synced_at' => now()]);
        }

        $this->persistSyncedTargets($server, $targets);

        $summary = implode("\n", $chunks);

        $server->refresh();

        $payload = $this->buildSyncPayload($server->fresh(['authorizedKeys']), $targets);
        $this->auditLogger->record(
            $server,
            ServerSshKeyAuditEvent::EVENT_SYNC_COMPLETED,
            $payload,
            $initiatedBy,
            $ipAddress
        );

        $healthKey = config('server_ssh_keys.meta_health_check_key');
        $healthMeta = null;
        if (data_get($server->meta, $healthKey)) {
            $healthMeta = $this->healthCheck->run($server->fresh());
        }

        Event::dispatch(new ServerAuthorizedKeysSynced(
            $server->fresh(),
            $initiatedBy,
            $summary,
            array_merge($payload, [
                'health' => $healthMeta,
            ])
        ));

        return $summary;
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, mixed>
     */
    protected function buildSyncPayload(Server $server, array $targets): array
    {
        $fingerprints = [];
        foreach ($server->authorizedKeys as $row) {
            $fp = SshPublicKeyFingerprint::forLine((string) $row->public_key);
            $fingerprints[$row->id] = [
                'name' => $row->name,
                'sha256' => $fp['sha256'] ?? null,
                'md5' => $fp['md5'] ?? null,
            ];
        }

        return [
            'targets' => $targets,
            'key_count' => $server->authorizedKeys->count(),
            'fingerprints_by_key_id' => $fingerprints,
        ];
    }

    /**
     * Users whose authorized_keys files we rewrite: every Linux user that still has rows in the
     * panel, plus users we synced before (so removing the last key for a user clears the remote
     * file), plus the SSH login user (so an empty panel clears the deploy account).
     *
     * @param  Collection<string, Collection<int, ServerAuthorizedKey>>  $groups
     * @return list<string>
     */
    protected function resolveSyncTargets(Server $server, Collection $groups): array
    {
        $connectionUser = (string) $server->ssh_user;

        $prev = data_get($server->meta, self::META_SYNCED_LINUX_USERS_KEY, []);
        $prev = is_array($prev) ? array_values(array_filter(array_map('strval', $prev))) : [];

        $fromDb = $groups->keys()->map(fn ($k) => (string) $k)->all();

        $targets = array_values(array_unique(array_merge(
            $prev,
            $fromDb,
            [$connectionUser],
            $this->hiddenManagedTargets($server)
        )));
        sort($targets);

        return $targets;
    }

    /**
     * @param  list<string>  $targets
     */
    protected function persistSyncedTargets(Server $server, array $targets): void
    {
        $server->refresh();
        $meta = $server->meta ?? [];
        $meta[self::META_SYNCED_LINUX_USERS_KEY] = $targets;
        $server->update(['meta' => $meta]);
    }

    protected function normalizedTargetUser(Server $server, string $raw): string
    {
        $t = trim($raw);

        return $t === '' ? (string) $server->ssh_user : $t;
    }

    /**
     * Replace remote authorized_keys with exactly the public keys from the panel for this user.
     *
     * @param  Collection<int, ServerAuthorizedKey>  $rows
     */
    protected function writeAuthorizedKeysForTarget(Server $server, string $connectionUser, string $targetUser, Collection $rows): string
    {
        $lines = $this->desiredAuthorizedKeyLines($server, $connectionUser, $targetUser, $rows);

        $body = implode("\n", $lines);
        if ($body !== '') {
            $body .= "\n";
        }

        $tmp = '/tmp/dply_authorized_keys_'.bin2hex(random_bytes(6));
        $b64 = base64_encode($body);

        // Single self-detecting script: at runtime the bash decides whether to write directly
        // (running as the target user) or sudo to the target. The previous implementation
        // picked between two scripts based on the model's `ssh_user`, which broke whenever
        // root-first SSH (USE_ROOT_SSH=true) succeeded and the script ended up writing
        // /root/.ssh/authorized_keys instead of the dply user's file. `id -un` is the truth.
        $script = $this->buildWriteScript($targetUser, $tmp, $b64);

        // Friendly preamble in the streaming buffer so the operator can tell what's happening
        // without parsing raw bash output. Includes the desired key count for that user.
        if ($this->outputCallback !== null) {
            $callback = $this->outputCallback;
            $callback('out', sprintf("> Writing authorized_keys for %s (%d keys)…\n", $targetUser, count($lines)));
        }

        $out = $this->runSyncScript($server, 'Write authorized_keys ('.$targetUser.')', $script, 60);

        if (! $out->isSuccessful()) {
            throw new \RuntimeException('Failed to update authorized_keys for '.$targetUser.': '.$out->getBuffer());
        }

        if (! str_contains($out->getBuffer(), 'DPLY_AUTH_EXIT:0')) {
            throw new \RuntimeException('Failed to update authorized_keys for '.$targetUser.': '.$out->getBuffer());
        }

        return $out->getBuffer();
    }

    /**
     * @param  Collection<int, ServerAuthorizedKey>  $rows
     * @return list<string>
     */
    protected function desiredAuthorizedKeyLines(Server $server, string $connectionUser, string $targetUser, Collection $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $key = trim((string) $row->public_key);
            if ($key !== '') {
                $lines[] = $key;
            }
        }

        if ($targetUser === $connectionUser) {
            $operationalKey = trim((string) $server->openSshPublicKeyFromOperationalPrivate());
            if ($operationalKey !== '') {
                $lines[] = $operationalKey;
            }
        }

        if ($targetUser === 'root') {
            $recoveryKey = trim((string) $server->openSshPublicKeyFromRecoveryPrivate());
            if ($recoveryKey !== '') {
                $lines[] = $recoveryKey;
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function hiddenManagedTargets(Server $server): array
    {
        return trim((string) $server->openSshPublicKeyFromRecoveryPrivate()) !== ''
            ? ['root']
            : [];
    }

    protected function runSyncScript(Server $server, string $name, string $script, int $timeoutSeconds): ProcessOutput
    {
        $useRoot = (bool) config('server_ssh_keys.use_root_ssh', true);
        $fallback = (bool) config('server_ssh_keys.fallback_to_deploy_user_ssh', true);
        $callback = $this->outputCallback;

        // Stream-only path: no root attempt, just run as deploy user with the callback.
        if (! $useRoot) {
            return $callback === null
                ? $this->remote->runScript($server, $name, $script, $timeoutSeconds, false)
                : $this->remote->runScriptWithOutputCallback($server, $name, $script, $callback, $timeoutSeconds, false);
        }

        // Root attempt first, BUFFERED. We deliberately don't pass the callback so a failed
        // root attempt doesn't pollute the streamed transcript with "Permission denied
        // (publickey)" noise the operator can do nothing about. On success we replay the
        // captured buffer through the callback in one chunk.
        try {
            $output = $this->remote->runScript($server, $name, $script, $timeoutSeconds, true);
            if ($callback !== null) {
                $callback('out', $output->getBuffer());
            }

            return $output;
        } catch (\Throwable $e) {
            if (! $fallback) {
                throw $e;
            }

            // Fall back to deploy-user SSH with streaming live — this attempt's output is the
            // one the operator actually wants to see.
            return $callback === null
                ? $this->remote->runScript($server, $name, $script, $timeoutSeconds, false)
                : $this->remote->runScriptWithOutputCallback($server, $name, $script, $callback, $timeoutSeconds, false);
        }
    }

    /**
     * Single write script that figures out at runtime whether to write directly or sudo.
     * The inner block does the actual work (decode → tempfile → mv into ~/.ssh/authorized_keys);
     * the outer block dispatches it based on `id -un`. We base64-wrap the inner so the sudo
     * branch can pass it as a single argument without quote gymnastics.
     */
    protected function buildWriteScript(string $targetUser, string $tmp, string $b64): string
    {
        $inner = '';
        $inner .= 'set -euo pipefail'."\n";
        $inner .= 'BODY=$(echo '.escapeshellarg($b64).' | base64 -d)'."\n";
        $inner .= 'TMP='.escapeshellarg($tmp)."\n";
        $inner .= 'printf %s "$BODY" > "$TMP"'."\n";
        $inner .= 'mkdir -p ~/.ssh && chmod 700 ~/.ssh'."\n";
        $inner .= 'mv "$TMP" ~/.ssh/authorized_keys'."\n";
        $inner .= 'chmod 600 ~/.ssh/authorized_keys'."\n";
        $inner .= 'printf "DPLY_AUTH_EXIT:%s" "$?"'."\n";

        $innerB64 = base64_encode($inner);

        $script = "#!/bin/bash\nset -euo pipefail\n";
        $script .= 'TARGET='.escapeshellarg($targetUser)."\n";
        $script .= 'INNER_B64='.escapeshellarg($innerB64)."\n";
        $script .= 'if [ "$(id -un)" = "$TARGET" ]; then'."\n";
        $script .= '    bash -lc "$(echo "$INNER_B64" | base64 -d)"'."\n";
        $script .= 'else'."\n";
        $script .= '    sudo -n -u "$TARGET" bash -lc "$(echo "$INNER_B64" | base64 -d)"'."\n";
        $script .= 'fi'."\n";

        return $script;
    }
}
