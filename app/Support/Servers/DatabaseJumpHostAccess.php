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
     * Kept for the on-box Networking tab; delegates to the target-based API.
     *
     * @return array{tunnel: string, connect: string, local_port: int}
     */
    public static function commandsFor(ServerDatabase $db, Server $dbServer, Server $jumpHost, int $enginePort, int $localPort): array
    {
        $host = trim((string) $dbServer->private_ip_address) !== ''
            ? trim((string) $dbServer->private_ip_address)
            : trim((string) $dbServer->ip_address);

        return self::tunnelCommandsFor(
            DatabaseConnectionTarget::fromServerDatabase($db, $host, $enginePort),
            $jumpHost,
            $localPort,
        );
    }

    /**
     * Tunnel + client commands for reaching any database through $jumpHost.
     *
     * @return array{tunnel: string, connect: string, uri: string, local_port: int}
     */
    public static function tunnelCommandsFor(
        DatabaseConnectionTarget $target,
        Server $jumpHost,
        int $localPort,
        ?string $identityFile = null,
    ): array {
        return [
            'tunnel' => sprintf(
                'ssh%s%s -L %d:%s:%d %s@%s',
                self::identityFlags($identityFile),
                self::sshPortFlag($jumpHost),
                $localPort,
                $target->host,
                $target->port,
                self::sshUserFor($jumpHost),
                $jumpHost->ip_address,
            ),
            'connect' => $target->clientCommand('127.0.0.1', $localPort),
            'uri' => $target->uri(null, '127.0.0.1', $localPort),
            'local_port' => $localPort,
        ];
    }

    /**
     * ssh_user is NOT NULL with a 'root' default, and SshConnection falls back to
     * root too — so root, not 'deploy', is the correct last resort here.
     */
    public static function sshUserFor(Server $jumpHost): string
    {
        $user = trim((string) $jumpHost->ssh_user);

        return $user !== '' ? $user : 'root';
    }

    /**
     * Pin the identity so ssh offers exactly one key.
     *
     * Without this, ssh walks every key in the agent in turn and a developer
     * carrying more than a handful trips the server's MaxAuthTries (6 by
     * default) before the right one is reached — surfacing as the misleading
     * "Too many authentication failures", which reads like a credentials
     * problem rather than an ordering one. IdentitiesOnly stops the agent from
     * adding its own keys back into the offer list.
     */
    private static function identityFlags(?string $identityFile): string
    {
        $identityFile = trim((string) $identityFile);
        if ($identityFile === '') {
            return '';
        }

        // A leading ~ must stay unquoted or the shell will not expand it, and
        // `ssh -i '~/.ssh/id_ed25519'` fails looking for a literal ~ directory.
        // Only skip quoting for paths that cannot mean anything else.
        $safeUnquoted = preg_match('#^[~/][A-Za-z0-9._/-]*$#', $identityFile) === 1;

        return ' -o IdentitiesOnly=yes -i '.($safeUnquoted ? $identityFile : escapeshellarg($identityFile));
    }

    /**
     * servers.ssh_port is a real column and non-22 ports are supported, so an
     * emitted command that omits -p is simply wrong for those hosts.
     */
    private static function sshPortFlag(Server $jumpHost): string
    {
        $port = (int) ($jumpHost->ssh_port ?: 22);

        return $port === 22 ? '' : ' -p '.$port;
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
