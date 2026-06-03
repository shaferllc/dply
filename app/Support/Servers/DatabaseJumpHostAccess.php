<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use Illuminate\Support\Collection;

/**
 * Builds "reach a locked-down database through a jump host" access helpers.
 *
 * Once a database is scoped to a CIDR (the secure default), you can no longer
 * connect to it directly from a laptop — and tunnelling through the database
 * server's OWN ssh doesn't help, because Postgres/MySQL then sees the source as
 * the database box itself, which isn't in the allowlist. The connection has to
 * originate from a host that IS allowlisted.
 *
 * This class finds the org's servers whose private IP falls inside the
 * database's `allowed_from` and turns each into a ready `ssh -L` tunnel plus the
 * matching client command. Pure + read-only.
 */
final class DatabaseJumpHostAccess
{
    /** Local port the tunnel binds on the operator's machine. */
    public const BASE_LOCAL_PORT = 15432;

    /**
     * Ready servers that can act as a jump host for this database: a peer whose
     * private IP is inside the database's allowed_from, with a reachable public
     * IP. The database's own host is never a valid jump host (its source isn't
     * allowlisted).
     *
     * @param  Collection<int, Server>  $candidates
     * @return Collection<int, Server>
     */
    public static function eligibleJumpHosts(ServerDatabase $db, Server $dbServer, Collection $candidates): Collection
    {
        if (! $db->remote_access) {
            return collect();
        }

        $cidrs = DedicatedCacheServerProvisionConfig::splitAllowedFrom((string) $db->allowed_from);
        if ($cidrs === []) {
            return collect();
        }

        return $candidates
            ->filter(fn (Server $s): bool => (string) $s->id !== (string) $dbServer->id)
            ->filter(fn (Server $s): bool => trim((string) $s->ip_address) !== '' && trim((string) $s->private_ip_address) !== '')
            ->filter(fn (Server $s): bool => self::ipInAnyCidr((string) $s->private_ip_address, $cidrs))
            ->values();
    }

    /**
     * Tunnel + client commands for reaching $db (on $dbServer) through $jumpHost.
     *
     * @return array{tunnel: string, connect: string, local_port: int}
     */
    public static function commandsFor(ServerDatabase $db, Server $dbServer, Server $jumpHost, int $enginePort, int $localPort): array
    {
        $user = trim((string) $jumpHost->ssh_user) !== '' ? trim((string) $jumpHost->ssh_user) : 'deploy';
        $target = trim((string) $dbServer->private_ip_address) !== ''
            ? trim((string) $dbServer->private_ip_address)
            : trim((string) $dbServer->ip_address);

        return [
            'tunnel' => sprintf('ssh -L %d:%s:%d %s@%s', $localPort, $target, $enginePort, $user, $jumpHost->ip_address),
            'connect' => self::clientConnect($db, $localPort),
            'local_port' => $localPort,
        ];
    }

    private static function clientConnect(ServerDatabase $db, int $localPort): string
    {
        $user = (string) ($db->username ?? 'app');

        return match ($db->engine) {
            'mysql', 'mariadb' => sprintf('mysql -h 127.0.0.1 -P %d -u %s -p %s', $localPort, $user, $db->name),
            default => sprintf('psql "host=127.0.0.1 port=%d user=%s dbname=%s"', $localPort, $user, $db->name),
        };
    }

    /**
     * @param  list<string>  $cidrs
     */
    public static function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $ip falls inside $cidr. Handles IPv4 and IPv6; a bare IP (no /n) is
     * treated as an exact match. A /0 matches everything.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        $cidr = trim($cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (! str_contains($cidr, '/')) {
            $cidrBin = @inet_pton($cidr);

            return $cidrBin !== false && $cidrBin === $ipBin;
        }

        [$subnet, $bitsRaw] = explode('/', $cidr, 2);
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }

        $bits = (int) $bitsRaw;
        $maxBits = strlen($ipBin) * 8;
        if ($bits <= 0) {
            return true;
        }
        if ($bits > $maxBits) {
            $bits = $maxBits;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
