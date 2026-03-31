<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use Illuminate\Support\Collection;

class ServerAuthorizedKeysDiffPreview
{
    public function __construct(
        protected ServerAuthorizedKeysRemoteReader $reader,
    ) {}

    /**
     * @return array<string, array{remote: list<string>, desired: list<string>, added: list<string>, removed: list<string>}>
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

        $out = [];
        foreach ($targets as $targetUser) {
            $rows = $groups->get($targetUser) ?? collect();
            $desired = $this->desiredLines($rows);
            $remote = $this->reader->normalizedKeyLines($server, $targetUser);
            $out[$targetUser] = [
                'remote' => $remote,
                'desired' => $desired,
                'added' => array_values(array_diff($desired, $remote)),
                'removed' => array_values(array_diff($remote, $desired)),
            ];
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
}
