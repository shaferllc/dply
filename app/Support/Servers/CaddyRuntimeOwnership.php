<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Idempotent shell to ensure the `caddy` system account exists and owns runtime
 * dirs. Root-run `caddy validate` can create access-log files owned by root;
 * the daemon runs as `caddy` and then fails with "permission denied" on start.
 */
final class CaddyRuntimeOwnership
{
    public static function shell(): string
    {
        return <<<'BASH'
getent group caddy >/dev/null 2>&1 || groupadd --system caddy
id -u caddy >/dev/null 2>&1 || useradd --system --gid caddy --no-create-home \
  --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy
mkdir -p /var/lib/caddy /var/log/caddy
chown -R caddy:caddy /var/lib/caddy /var/log/caddy
chmod 0755 /var/log/caddy
chmod 0750 /var/lib/caddy
BASH;
    }

    public static function validateCommand(): string
    {
        return 'sudo -u caddy caddy validate --config /etc/caddy/Caddyfile';
    }
}
