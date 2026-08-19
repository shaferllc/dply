<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\CloudDatabase;
use App\Models\ServerDatabase;

/**
 * A database an operator wants to reach from their own machine, flattened to the
 * handful of facts a tunnel command / connection URI needs.
 *
 * dply stores database connection details in three unrelated shapes — on-box
 * rows ({@see ServerDatabase}), managed clusters ({@see CloudDatabase}, one
 * encrypted `connection` blob), and operator-typed hosts (only the binding's
 * encrypted `injected_env`). Every consumer that wanted to say "connect to this"
 * had to re-learn all three. This is the single shape they agree on.
 *
 * Deliberately carries NO password: instances flow into view data, and the
 * password must only ever travel through the one-time credential-share channel.
 */
final class DatabaseConnectionTarget
{
    public const KIND_ON_BOX = 'on_box';

    public const KIND_MANAGED = 'managed';

    public const KIND_EXTERNAL = 'external';

    /**
     * @param  string  $engine  postgres | mysql | mariadb | redis | …
     * @param  string  $host  address to dial *from the jump host*
     * @param  string  $kind  one of the KIND_* constants
     * @param  bool  $publiclyReachable  true when the provider exposes the database
     *                                   to the internet and no tunnel is required
     * @param  bool  $supportsTrustedSourceWrites  true when dply can add an
     *                                             operator IP to the allowlist
     */
    public function __construct(
        public readonly string $engine,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly ?string $sslMode = null,
        public readonly string $kind = self::KIND_MANAGED,
        public readonly bool $publiclyReachable = false,
        public readonly bool $supportsTrustedSourceWrites = false,
        public readonly string $label = '',
    ) {}

    public static function fromServerDatabase(ServerDatabase $db, string $host, int $port): self
    {
        return new self(
            engine: (string) $db->engine,
            host: $host,
            port: $port,
            database: (string) $db->name,
            username: (string) ($db->username ?? 'app'),
            sslMode: null,
            kind: self::KIND_ON_BOX,
            publiclyReachable: false,
            supportsTrustedSourceWrites: false,
            label: (string) $db->name,
        );
    }

    /**
     * @param  array<string, mixed>  $connection  the decrypted `connection` blob
     */
    public static function fromCloudDatabase(CloudDatabase $database, array $connection): self
    {
        $ssl = ($connection['ssl'] ?? false) === true
            || in_array((string) ($connection['sslmode'] ?? ''), ['require', 'verify-full', 'verify-ca'], true);

        return new self(
            engine: (string) $database->engine,
            host: (string) ($connection['host'] ?? ''),
            port: (int) ($connection['port'] ?? self::defaultPortFor((string) $database->engine)),
            database: (string) ($connection['database'] ?? ''),
            username: (string) ($connection['username'] ?? ''),
            sslMode: $ssl ? (string) ($connection['sslmode'] ?? 'require') : null,
            kind: $database->backend === CloudDatabase::BACKEND_EXTERNAL ? self::KIND_EXTERNAL : self::KIND_MANAGED,
            publiclyReachable: self::backendIsPubliclyReachable((string) $database->backend),
            supportsTrustedSourceWrites: self::backendSupportsTrustedSourceWrites((string) $database->backend),
            label: (string) $database->name,
        );
    }

    /**
     * Serverless vendors hand out a public endpoint guarded by credentials and
     * TLS — there is nothing to tunnel through and no allowlist to edit.
     */
    public static function backendIsPubliclyReachable(string $backend): bool
    {
        return in_array($backend, [
            CloudDatabase::BACKEND_NEON,
            CloudDatabase::BACKEND_PLANETSCALE,
            CloudDatabase::BACKEND_SUPABASE,
            CloudDatabase::BACKEND_UPSTASH,
        ], true);
    }

    /**
     * Only the two IaaS-style managed backends expose a trusted-source API that
     * dply holds credentials for. External hosts are not ours to firewall.
     */
    public static function backendSupportsTrustedSourceWrites(string $backend): bool
    {
        return in_array($backend, [
            CloudDatabase::BACKEND_DIGITALOCEAN,
            CloudDatabase::BACKEND_VULTR,
        ], true);
    }

    public static function defaultPortFor(string $engine): int
    {
        return match ($engine) {
            'mysql', 'mariadb' => 3306,
            'redis' => 6379,
            'mongodb' => 27017,
            'clickhouse' => 8123,
            default => 5432,
        };
    }

    /**
     * The same database reached as a different user. Used when the operator
     * connects as something other than the cluster admin.
     */
    public function as(string $username): self
    {
        $username = trim($username);
        if ($username === '' || $username === $this->username) {
            return $this;
        }

        return new self(
            engine: $this->engine,
            host: $this->host,
            port: $this->port,
            database: $this->database,
            username: $username,
            sslMode: $this->sslMode,
            kind: $this->kind,
            publiclyReachable: $this->publiclyReachable,
            supportsTrustedSourceWrites: $this->supportsTrustedSourceWrites,
            label: $this->label,
        );
    }

    public function isRedis(): bool
    {
        return $this->engine === 'redis';
    }

    public function isMysqlFamily(): bool
    {
        return in_array($this->engine, ['mysql', 'mariadb'], true);
    }

    public function uriScheme(): string
    {
        return match (true) {
            $this->isMysqlFamily() => 'mysql',
            $this->isRedis() => 'rediss',
            default => 'postgresql',
        };
    }

    /**
     * Connection URI for a client.
     *
     * Pass $password only on the credential-share path; everywhere else leave it
     * null and a placeholder is emitted, so a URI can be rendered in the UI
     * without the secret. $host/$port override for the tunnel case, where the
     * client dials 127.0.0.1 on the forwarded port instead of the real host.
     */
    public function uri(?string $password = null, ?string $host = null, ?int $port = null): string
    {
        // With no password the credential section carries the username alone.
        // A literal PASSWORD placeholder used to be emitted here, which produced
        // a command that looked copy-pasteable and simply failed to authenticate;
        // omitting it instead yields a URI clients accept and then prompt for.
        $credentials = $password === null
            ? rawurlencode($this->username)
            : rawurlencode($this->username).':'.rawurlencode($password);

        $uri = sprintf(
            '%s://%s@%s:%d/%s',
            $this->uriScheme(),
            $credentials,
            $host ?? $this->host,
            $port ?? $this->port,
            rawurlencode($this->database),
        );

        // Through a local forward the client dials 127.0.0.1, so the certificate
        // hostname can never match — verify-full would always fail. SSH has
        // already authenticated the transport, so require is the honest mode.
        $sslMode = $host !== null ? 'require' : $this->sslMode;
        if ($sslMode !== null && ! $this->isMysqlFamily() && ! $this->isRedis()) {
            $uri .= '?sslmode='.$sslMode;
        }

        return $uri;
    }

    /** A ready-to-paste CLI invocation, mirroring the URI's host/port rules. */
    public function clientCommand(?string $host = null, ?int $port = null): string
    {
        $host ??= $this->host;
        $port ??= $this->port;

        return match (true) {
            $this->isMysqlFamily() => sprintf(
                'mysql -h %s -P %d -u %s -p %s',
                $host,
                $port,
                $this->username,
                $this->database,
            ),
            $this->isRedis() => sprintf('redis-cli -h %s -p %d --tls --askpass', $host, $port),
            default => sprintf(
                'psql "host=%s port=%d user=%s dbname=%s"',
                $host,
                $port,
                $this->username,
                $this->database,
            ),
        };
    }
}
