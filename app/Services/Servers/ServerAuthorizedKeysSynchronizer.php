<?php

namespace App\Services\Servers;

use App\Events\Servers\ServerAuthorizedKeysSynced;
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

    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
        protected ServerAuthorizedKeysAuditLogger $auditLogger,
        protected ServerAuthorizedKeysHealthCheck $healthCheck,
    ) {}

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

        $script = $targetUser === $connectionUser
            ? $this->buildWriteScriptForCurrentUser($tmp, $b64)
            : $this->buildWriteScriptForSudoUser($targetUser, $tmp, $b64);

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

        if (! $useRoot) {
            return $this->remote->runScript($server, $name, $script, $timeoutSeconds, false);
        }

        try {
            return $this->remote->runScript($server, $name, $script, $timeoutSeconds, true);
        } catch (\Throwable $e) {
            if (! $fallback) {
                throw $e;
            }

            return $this->remote->runScript($server, $name, $script, $timeoutSeconds, false);
        }
    }

    protected function buildWriteScriptForCurrentUser(string $tmp, string $b64): string
    {
        $script = "#!/bin/bash\nset -euo pipefail\n";
        $script .= 'BODY=$(echo '.escapeshellarg($b64).' | base64 -d)'."\n";
        $script .= 'TMP='.escapeshellarg($tmp)."\n";
        $script .= 'printf %s "$BODY" > "$TMP"'."\n";
        $script .= 'mkdir -p ~/.ssh && chmod 700 ~/.ssh'."\n";
        $script .= 'mv "$TMP" ~/.ssh/authorized_keys'."\n";
        $script .= 'chmod 600 ~/.ssh/authorized_keys'."\n";
        $script .= 'printf "DPLY_AUTH_EXIT:%s" "$?"'."\n";

        return $script;
    }

    protected function buildWriteScriptForSudoUser(string $targetUser, string $tmp, string $b64): string
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
        $script .= 'sudo -n -u '.escapeshellarg($targetUser).' bash -lc "$(echo '.escapeshellarg($innerB64).' | base64 -d)"'."\n";

        return $script;
    }
}
