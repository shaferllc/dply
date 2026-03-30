<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Support\Facades\Gate;

class UserSshKeyDeploymentService
{
    public function __construct(
        protected ServerAuthorizedKeysSynchronizer $synchronizer
    ) {}

    /**
     * Attach account keys marked “provision on new servers” and push authorized_keys.
     */
    public function provisionDefaultsForNewServer(Server $server): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $keys = UserSshKey::query()
            ->where('user_id', $server->user_id)
            ->where('provision_on_new_servers', true)
            ->get();

        if ($keys->isEmpty()) {
            return;
        }

        foreach ($keys as $key) {
            $this->ensureServerRow($key, $server);
        }

        $this->synchronizer->sync($server->fresh());
    }

    /**
     * Ensure a row exists on the server for this account key, then sync.
     *
     * @return array{ok: bool, message: string}
     */
    public function deployToServer(UserSshKey $userSshKey, Server $server): array
    {
        if (! Gate::forUser($userSshKey->user)->allows('update', $server)) {
            return ['ok' => false, 'message' => 'You cannot update this server.'];
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['ok' => false, 'message' => 'Server must be ready with an SSH key before syncing keys.'];
        }

        $this->ensureServerRow($userSshKey, $server);
        $this->synchronizer->sync($server->fresh());

        return ['ok' => true, 'message' => 'authorized_keys updated on '.$server->name.'.'];
    }

    /**
     * @param  array<int, string>  $serverIds  Server primary keys (ULIDs)
     * @return array{ok: bool, message: string, errors: array<int, string>}
     */
    public function deployToServers(User $user, UserSshKey $userSshKey, array $serverIds): array
    {
        if ($userSshKey->user_id !== $user->id) {
            return ['ok' => false, 'message' => 'Invalid key.', 'errors' => []];
        }

        $servers = Server::query()->whereIn('id', $serverIds)->get();
        $errors = [];

        foreach ($servers as $server) {
            $result = $this->deployToServer($userSshKey, $server);
            if (! $result['ok']) {
                $errors[$server->id] = $server->name.': '.$result['message'];
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'message' => 'Some servers could not be updated.',
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Keys deployed to '.count($servers).' server(s).',
            'errors' => [],
        ];
    }

    public function ensureServerRow(UserSshKey $userSshKey, Server $server): ServerAuthorizedKey
    {
        return ServerAuthorizedKey::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'user_ssh_key_id' => $userSshKey->id,
            ],
            [
                'name' => $userSshKey->name,
                'public_key' => trim($userSshKey->public_key),
            ]
        );
    }

    /**
     * After the account key changes, sync linked server rows.
     */
    public function syncLinkedServerRows(UserSshKey $userSshKey): void
    {
        ServerAuthorizedKey::query()
            ->where('user_ssh_key_id', $userSshKey->id)
            ->update([
                'name' => $userSshKey->name,
                'public_key' => trim($userSshKey->public_key),
            ]);
    }
}
