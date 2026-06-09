<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;

final class FakeCloudProvision
{
    /**
     * Providers that use Provision* → Poll* → STATUS_READY → stack setup.
     */
    private const VM_POLL_PROVIDERS = [
        ServerProvider::DigitalOcean,
        ServerProvider::Hetzner,
        ServerProvider::Linode,
        ServerProvider::Vultr,
        ServerProvider::UpCloud,
        ServerProvider::Aws,
        ServerProvider::Azure,
        ServerProvider::Oracle,
    ];

    public static function enabled(): bool
    {
        // Re-read .env directly at decision time so long-running queue
        // workers that booted with env_flag=true don't keep routing
        // provision jobs through fake-cloud after the operator flips
        // the flag off. Laravel's config() returns the boot-time
        // cached value; that's safe inside a request lifecycle but
        // hostile to a worker that's been alive for hours and has
        // every reason to think config is immutable.
        $live = self::liveEnvFlag();
        if ($live !== null) {
            if (! $live) {
                return false;
            }
        } elseif (! config('server_provision_fake.env_flag')) {
            return false;
        }

        return in_array(app()->environment(), config('server_provision_fake.allowed_environments', []), true);
    }

    /**
     * Re-read DPLY_FAKE_CLOUD_PROVISION from disk, bypassing the
     * config cache. Returns null when .env is unreadable or the key
     * isn't present (callers fall back to config()-cached value).
     * Only fires in environments where .env lives on the filesystem;
     * production deployments without an on-disk .env always hit the
     * fallback.
     */
    private static function liveEnvFlag(): ?bool
    {
        // Tests flip the env_flag via config([...]) setters and don't
        // touch the real .env file — bypass the live read entirely so
        // test setup stays in control of what enabled() returns.
        if (app()->environment('testing')) {
            return null;
        }

        static $cache = null;
        static $cachedAt = null;

        // 5s in-process cache so `shouldIntercept` calls within a
        // single job don't stat() the file thousands of times. Short
        // enough to notice .env changes within a few seconds.
        $now = microtime(true);
        if ($cachedAt !== null && ($now - $cachedAt) < 5.0) {
            return $cache;
        }

        $envPath = base_path('.env');
        if (! is_readable($envPath)) {
            $cache = null;
            $cachedAt = $now;

            return null;
        }

        $contents = @file_get_contents($envPath);
        if ($contents === false) {
            $cache = null;
            $cachedAt = $now;

            return null;
        }

        if (preg_match('/^\s*DPLY_FAKE_CLOUD_PROVISION\s*=\s*(.+)$/m', $contents, $m)) {
            $value = trim($m[1]);
            // Strip surrounding quotes — .env values often wrapped in
            // "..." or '...' even for booleans.
            $value = trim($value, "\"'");
            $cache = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } else {
            $cache = null;
        }
        $cachedAt = $now;

        return $cache;
    }

    public static function sentinelProviderId(): string
    {
        return (string) config('server_provision_fake.provider_id_sentinel', 'fake-local');
    }

    public static function isFakeServer(?Server $server): bool
    {
        if ($server === null) {
            return false;
        }

        // The env flag has to be on AND the row has to carry the
        // fake-local sentinel. Without the env-flag check, disabling
        // DPLY_FAKE_CLOUD_PROVISION leaves pre-existing fake servers
        // silently routing through local docker forever — every
        // subsequent provision attempt hits the dev SSH container
        // instead of the real provider, with no indication why.
        // The right semantics: flip the flag off, fake-cloud routing
        // stops, and orphan fake-* rows fail loudly against the real
        // provider so the operator notices + deletes them.
        if (! self::enabled()) {
            return false;
        }

        return (string) $server->provider_id === self::sentinelProviderId();
    }

    /**
     * True when the server row carries the fake-local sentinel BUT
     * the env flag is currently off. These are dead-end rows from a
     * prior fake-cloud session — their stored ip_address (127.0.0.1)
     * + ssh_port (2222) point at a docker container that may not even
     * be running. The journey UI should banner these clearly so the
     * operator deletes the orphan instead of staring at "SSH port not
     * reachable" wondering why nothing works.
     */
    public static function isOrphanedFakeServer(?Server $server): bool
    {
        if ($server === null) {
            return false;
        }

        if (self::enabled()) {
            return false;
        }

        return (string) $server->provider_id === self::sentinelProviderId();
    }

    public static function shouldInterceptVmProvision(Server $server): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return in_array($server->provider, self::VM_POLL_PROVIDERS, true);
    }

    /**
     * Fixed dev/test key from env or docker/ssh-dev file — same logic as ApplyFakeCloudProvisionAsReady.
     */
    public static function resolvedPrivateKey(): ?string
    {
        $inline = config('server_provision_fake.ssh_private_key');
        if (is_string($inline) && trim($inline) !== '') {
            return trim($inline);
        }

        $relative = (string) config('server_provision_fake.ssh_private_key_path', '');
        if ($relative === '') {
            return null;
        }

        $path = str_starts_with($relative, DIRECTORY_SEPARATOR)
            ? $relative
            : base_path($relative);

        if (! is_readable($path)) {
            return null;
        }

        return trim((string) file_get_contents($path));
    }

    /**
     * When this returns non-null, TaskRunner / SSH should use it instead of encrypted columns so
     * fake servers stay aligned with docker-compose.ssh-dev after changing bundled keys (old DB rows
     * may still hold previously generated material).
     */
    public static function sshPrivateKeyOverrideForFakeServer(Server $server): ?string
    {
        if (! self::isFakeServer($server)) {
            return null;
        }

        $resolved = self::resolvedPrivateKey();

        return ($resolved !== null && $resolved !== '') ? $resolved : null;
    }
}
