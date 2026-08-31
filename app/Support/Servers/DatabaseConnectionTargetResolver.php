<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;

/**
 * Turns a database {@see SiteBinding} into a {@see DatabaseConnectionTarget}.
 *
 * {@see SiteBinding::isRemoteConfigurableDatabase()} recognises four different
 * storage shapes; this is the one place that knows how to read each of them.
 */
final class DatabaseConnectionTargetResolver
{
    /** Reasons a database cannot be reached by tunnelling through a jump host. */
    public const REASON_PROVIDER_PUBLIC = 'provider_public';

    public const REASON_SERVER_NOT_SSHABLE = 'server_not_sshable';

    public const REASON_SERVER_NOT_READY = 'server_not_ready';

    public const REASON_NO_HOST = 'no_host';

    public function forBinding(SiteBinding $binding): ?DatabaseConnectionTarget
    {
        // sqlite is a file on disk: there is no daemon, no port, and nothing to
        // tunnel to. Guarded here rather than in each arm because the remote arm
        // would otherwise resolve the host server's IP and offer a connection
        // that cannot work.
        if ($this->bindingIsSqlite($binding)) {
            return null;
        }

        // An ON-BOX database (loopback, on the site's own server) is not
        // "remote configurable" — dply owns no provider firewall for it — but it
        // is still perfectly connectable, and in fact the simplest case: the
        // jump host and the database host are the same machine, so `ssh -L`
        // straight to 127.0.0.1 works. Resolve it before the remote gate, which
        // rejects loopback by design.
        $onBox = $this->onBoxTarget($binding);
        if ($onBox instanceof DatabaseConnectionTarget) {
            return $onBox;
        }

        if (! $binding->isRemoteConfigurableDatabase()) {
            return null;
        }

        if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
            $cluster = CloudDatabase::query()->find($binding->target_id);
            if (! $cluster instanceof CloudDatabase) {
                return null;
            }

            // NB: getAttribute(), not ->connection — the latter collides with
            // Eloquent's own $connection property from inside the model, and the
            // same trap applies to readers.
            $connection = $cluster->getAttribute('connection');
            $connection = is_array($connection) ? $connection : [];

            // Still provisioning: the blob stays empty until the host is known.
            if (($connection['host'] ?? '') === '') {
                return null;
            }

            return DatabaseConnectionTarget::fromCloudDatabase($cluster, $connection);
        }

        // A dedicated database VM: a real ServerDatabase row on a peer server.
        if ($binding->target_type === 'server_database' && filled($binding->target_id)) {
            $db = ServerDatabase::query()->find($binding->target_id);
            if ($db instanceof ServerDatabase) {
                $peer = Server::query()->find($db->server_id);
                $host = $peer instanceof Server
                    ? (trim((string) $peer->private_ip_address) ?: trim((string) $peer->ip_address))
                    : '';

                if ($host !== '') {
                    return DatabaseConnectionTarget::fromServerDatabase(
                        $db,
                        $host,
                        DatabaseConnectionTarget::defaultPortFor((string) $db->engine),
                    );
                }
            }
        }

        return $this->fromBindingEnvelope($binding);
    }

    private function bindingIsSqlite(SiteBinding $binding): bool
    {
        $config = is_array($binding->config) ? $binding->config : [];
        $engine = (string) ($config['engine'] ?? $binding->connectionEnv()['DB_CONNECTION'] ?? '');

        return $engine !== '' && DatabaseWorkspaceEngines::family($engine) === 'sqlite';
    }

    /**
     * A database living on the site's own server, dialled over loopback.
     *
     * Null unless the binding targets a ServerDatabase whose server IS the
     * site's server and whose host is loopback — the peer-server case
     * (a dedicated database VM) is handled by the remote arm, which can reach
     * it over the private network.
     */
    private function onBoxTarget(SiteBinding $binding): ?DatabaseConnectionTarget
    {
        if ($binding->type !== 'database' || $binding->target_type !== 'server_database' || blank($binding->target_id)) {
            return null;
        }

        $db = ServerDatabase::query()->find($binding->target_id);
        if (! $db instanceof ServerDatabase) {
            return null;
        }

        $host = strtolower(trim((string) $db->host));
        if (! in_array($host, ['', '127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }

        $site = $binding->site;
        if ($site === null || (string) $site->server_id !== (string) $db->server_id) {
            return null;
        }

        return DatabaseConnectionTarget::fromServerDatabase(
            $db,
            '127.0.0.1',
            DatabaseConnectionTarget::defaultPortFor((string) $db->engine),
        );
    }

    /**
     * The fallback arm: an operator typed a remote host into the binding, so the
     * only record of it is the (encrypted) injected_env plus config. dply never
     * provisioned this database and cannot change its firewall.
     */
    private function fromBindingEnvelope(SiteBinding $binding): ?DatabaseConnectionTarget
    {
        $config = is_array($binding->config) ? $binding->config : [];
        $env = $binding->connectionEnv();

        $host = trim((string) ($config['host'] ?? $env['DB_HOST'] ?? ''));
        if ($host === '' || in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }

        $engine = strtolower(trim((string) ($config['engine'] ?? $env['DB_CONNECTION'] ?? 'postgres')));
        $engine = match ($engine) {
            'pgsql' => 'postgres',
            default => $engine,
        };

        $port = (int) ($config['port'] ?? $env['DB_PORT'] ?? 0);

        return new DatabaseConnectionTarget(
            engine: $engine,
            host: $host,
            port: $port > 0 ? $port : DatabaseConnectionTarget::defaultPortFor($engine),
            database: (string) ($config['database'] ?? $env['DB_DATABASE'] ?? ''),
            username: (string) ($config['username'] ?? $env['DB_USERNAME'] ?? ''),
            sslMode: ($env['DB_SSLMODE'] ?? $config['db_sslmode'] ?? null) ?: null,
            kind: DatabaseConnectionTarget::KIND_EXTERNAL,
            publiclyReachable: false,
            supportsTrustedSourceWrites: false,
            label: (string) ($binding->name ?: $host),
        );
    }

    /**
     * Why a tunnel is unavailable, or null when one can be built.
     *
     * The jump host is the site's own server. Note this is NOT a null-server
     * check: sites.server_id is NOT NULL, so a site always has a server row —
     * but serverless and Cloud sites carry a synthetic host that does not accept
     * SSH at all, which supportsSsh() (true only for HOST_KIND_VM) detects.
     */
    public function tunnelUnavailableReason(DatabaseConnectionTarget $target, ?Server $jumpHost): ?string
    {
        if ($target->publiclyReachable) {
            return self::REASON_PROVIDER_PUBLIC;
        }

        if ($target->host === '') {
            return self::REASON_NO_HOST;
        }

        if (! $jumpHost instanceof Server || ! $jumpHost->hostCapabilities()->supportsSsh()) {
            return self::REASON_SERVER_NOT_SSHABLE;
        }

        if (! $jumpHost->isReady() || blank($jumpHost->ssh_private_key) || blank($jumpHost->ip_address)) {
            return self::REASON_SERVER_NOT_READY;
        }

        return null;
    }
}
