<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Sites\DatabaseConnect;
use App\Models\ServerDatabase;
use App\Support\Servers\DatabaseConnectionTarget;
use App\Support\Servers\DatabaseJumpHostAccess;
use App\Support\Servers\DatabaseWorkspaceEngines;

/**
 * "Connect with TablePlus / psql / any client" for a database on THIS server.
 *
 * The site-scoped {@see DatabaseConnect} panel is addressed
 * by SiteBinding id, and its credential-link / URI / terminal routes all take a
 * binding — none of which exist for a server-level database that no site has
 * attached. This is the same idea without the site: connection facts plus a
 * ready `ssh -L` tunnel through the server itself.
 *
 * The password is deliberately absent, exactly as in the site panel — it
 * travels only through the one-time credential-share channel, so nothing here
 * can leak it into the DOM. For an ADOPTED database dply has no password at
 * all, which the panel says plainly rather than rendering a blank field.
 */
trait ManagesDatabaseConnectPanel
{
    /** Database whose connect panel is open; null = closed. */
    public ?string $connectDatabaseId = null;

    /** Local port the emitted tunnel binds; editable because 15432 may be taken. */
    public int $connectLocalPort = DatabaseJumpHostAccess::BASE_LOCAL_PORT;

    /**
     * Local private key to pin with -i. dply knows which public key the box
     * authorizes but has no idea where the operator keeps the private half.
     */
    public string $connectSshKeyPath = '~/.ssh/id_ed25519';

    public function openDatabaseConnect(string $id): void
    {
        $this->authorize('view', $this->server);

        if ($this->connectableDatabase($id) === null) {
            return;
        }

        $this->connectDatabaseId = $id;
        $this->dispatch('open-modal', 'server-database-connect');
    }

    public function closeDatabaseConnect(): void
    {
        $this->connectDatabaseId = null;
        $this->dispatch('close-modal', 'server-database-connect');
    }

    public function updatedConnectLocalPort(): void
    {
        // Keep it in the ephemeral range and off privileged ports; a bad value
        // would otherwise be pasted into a shell command that silently fails.
        $this->connectLocalPort = max(1024, min(65535, (int) $this->connectLocalPort));
    }

    /**
     * View payload for the connect modal, or null when nothing is open.
     *
     * @return array<string, mixed>|null
     */
    public function databaseConnectPanel(): ?array
    {
        $db = $this->connectableDatabase((string) $this->connectDatabaseId);
        if ($db === null) {
            return null;
        }

        $target = DatabaseConnectionTarget::fromServerDatabase(
            $db,
            $db->host ?: '127.0.0.1',
            DatabaseConnectionTarget::defaultPortFor((string) $db->engine),
        );

        return [
            'database' => $db,
            'target' => $target,
            'commands' => DatabaseJumpHostAccess::tunnelCommandsFor(
                $target,
                $this->server,
                $this->connectLocalPort,
                $this->connectSshKeyPath,
            ),
            // False for an adopted database: dply never held the password, so
            // the client command will prompt for one the operator must know.
            'credentials_known' => $db->hasUsableCredentials(),
        ];
    }

    /**
     * A database on this server that can actually be dialled.
     *
     * sqlite is excluded: `host` is a file path, there is no port and no daemon
     * to tunnel to, so every command this panel emits would be nonsense.
     */
    private function connectableDatabase(string $id): ?ServerDatabase
    {
        if (trim($id) === '') {
            return null;
        }

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->find($id);
        if (! $db instanceof ServerDatabase) {
            return null;
        }

        return DatabaseWorkspaceEngines::family((string) $db->engine) === 'sqlite' ? null : $db;
    }
}
