<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use Illuminate\Support\Collection;

class ServerAuthorizedKeysDiffPreview
{
    /**
     * Optional callback to surface per-target progress to the workspace's "View output" console.
     * Same shape as {@see ServerAuthorizedKeysSynchronizer::withOutputCallback()} — we don't try
     * to stream raw bash since the read result is already rendered as the diff itself; what's
     * useful here is the high-level "what user did we just read" trail.
     *
     * @var (callable(string, string): void)|null
     */
    protected $outputCallback = null;

    public function __construct(
        protected ServerAuthorizedKeysRemoteReader $reader,
    ) {}

    /**
     * @param  callable(string $type, string $line): void  $callback
     */
    public function withOutputCallback(callable $callback): static
    {
        $this->outputCallback = $callback;

        return $this;
    }

    /**
     * @return array<string, array{remote: list<string>, desired: list<string>, added: list<string>, removed: list<string>, kept: list<string>}>
     */
    public function diffPerUser(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        $server->loadMissing('authorizedKeys');
        $connectionUser = (string) $server->ssh_user;

        /** @var Collection<string, Collection<int, ServerAuthorizedKey>> $groups */
        $groups = $server->authorizedKeys->groupBy(function (ServerAuthorizedKey $row) use ($server) {
            $raw = (string) ($row->target_linux_user ?? '');
            $t = trim($raw);

            return $t === '' ? (string) $server->ssh_user : $t;
        });

        $prev = data_get($server->meta, ServerAuthorizedKeysSynchronizer::META_SYNCED_LINUX_USERS_KEY, []);
        $prev = is_array($prev) ? array_values(array_filter(array_map('strval', $prev))) : [];

        $fromDb = $groups->keys()->map(fn ($k) => (string) $k)->all();
        $targets = array_values(array_unique(array_merge($prev, $fromDb, [$connectionUser])));
        sort($targets);

        // Dply uses the recovery key to log in as root for repair operations
        // (Server::connectionAsRecoveryRoot). That key MUST stay in root's
        // authorized_keys regardless of the panel state, otherwise the next sync
        // would lock Dply out of the box. Inject it into the desired set for root.
        $recoveryPubKey = trim((string) ($server->openSshPublicKeyFromRecoveryPrivate() ?? ''));
        // Same idea for the operational key (the one Dply uses for `connectionAsUser`):
        // the synchronizer always re-injects it into the connection user's desired set so
        // a sync never locks Dply out, and the diff preview must mirror that — otherwise
        // we'd show the operational key under "would remove" even though sync wouldn't
        // actually drop it.
        $operationalPubKey = trim((string) ($server->openSshPublicKeyFromOperationalPrivate() ?? ''));

        $callback = $this->outputCallback;
        if ($callback !== null) {
            $callback('out', sprintf('> Connecting to %s …', $server->getSshConnectionString()));
            $callback('out', sprintf('> Comparing %d target user(s): %s', count($targets), implode(', ', $targets)));
        }

        $out = [];
        foreach ($targets as $targetUser) {
            $rows = $groups->get($targetUser) ?? collect();
            $desired = $this->desiredLines($rows);

            if ($targetUser === 'root' && $recoveryPubKey !== '' && ! in_array($recoveryPubKey, $desired, true)) {
                $desired[] = $recoveryPubKey;
                sort($desired);
            }

            if ($targetUser === $connectionUser && $operationalPubKey !== '' && ! in_array($operationalPubKey, $desired, true)) {
                $desired[] = $operationalPubKey;
                sort($desired);
            }

            if ($callback !== null) {
                $callback('out', sprintf('> Reading authorized_keys for %s …', $targetUser));
            }

            $remote = $this->reader->normalizedKeyLines($server, $targetUser);

            // Mirror the synchronizer's non-destructive reconcile: a remote key is only "removed"
            // when dply itself previously placed it (its fingerprint is in the managed set) AND it's
            // no longer desired. Foreign / operator-added keys are preserved, not removed — so the
            // preview must show them under "kept", never under "removed".
            $desiredFps = $this->fingerprintSet($desired);
            $remoteFps = $this->fingerprintSet($remote);
            $managedMap = is_array(data_get($server->meta, ServerAuthorizedKeysSynchronizer::META_MANAGED_FINGERPRINTS_KEY))
                ? data_get($server->meta, ServerAuthorizedKeysSynchronizer::META_MANAGED_FINGERPRINTS_KEY)
                : [];
            $previouslyManaged = array_flip(array_map('strval', (array) ($managedMap[$targetUser] ?? [])));

            $added = [];
            $kept = [];
            foreach ($desired as $line) {
                $fp = SshPublicKeyFingerprint::shortSha256($line);
                if ($fp !== null && isset($remoteFps[$fp])) {
                    $kept[] = $line; // already on the box
                } else {
                    $added[] = $line;
                }
            }

            $removed = [];
            foreach ($remote as $line) {
                $fp = SshPublicKeyFingerprint::shortSha256($line);
                if ($fp !== null && isset($desiredFps[$fp])) {
                    continue; // counted as kept above
                }
                if ($fp !== null && isset($previouslyManaged[$fp])) {
                    $removed[] = $line; // dply-managed, dropped from the panel → will be removed
                } else {
                    $kept[] = $line; // foreign / unmanaged → preserved on the next sync
                }
            }

            $out[$targetUser] = [
                'remote' => $remote,
                'desired' => $desired,
                'added' => array_values($added),
                'removed' => array_values($removed),
                // Stays on the server after the next sync: desired keys already present PLUS every
                // foreign key dply preserves. The workspace shows these so operators see what's in
                // place (incl. Dply's auto-injected operational/recovery keys and their own keys).
                'kept' => array_values(array_unique($kept)),
            ];

            if ($callback !== null) {
                $addedCount = count($out[$targetUser]['added']);
                $removedCount = count($out[$targetUser]['removed']);
                $callback('out', sprintf(
                    '  %s: %d remote, %d desired · +%d -%d',
                    $targetUser,
                    count($remote),
                    count($desired),
                    $addedCount,
                    $removedCount,
                ));
            }
        }

        if ($callback !== null) {
            $callback('out', '> Done.');
        }

        return $out;
    }

    /**
     * @param  Collection<int, ServerAuthorizedKey>  $rows
     * @return list<string>
     */
    protected function desiredLines(Collection $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $key = trim((string) $row->public_key);
            if ($key !== '') {
                $lines[] = $key;
            }
        }
        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * Map of SHA256 fingerprint => true for every parseable key line (comment-independent).
     *
     * @param  list<string>  $lines
     * @return array<string, true>
     */
    protected function fingerprintSet(array $lines): array
    {
        $set = [];
        foreach ($lines as $line) {
            if ($fp = SshPublicKeyFingerprint::shortSha256($line)) {
                $set[$fp] = true;
            }
        }

        return $set;
    }
}
