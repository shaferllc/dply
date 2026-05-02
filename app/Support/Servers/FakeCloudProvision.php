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
        ServerProvider::Akamai,
        ServerProvider::Vultr,
        ServerProvider::Scaleway,
        ServerProvider::UpCloud,
        ServerProvider::EquinixMetal,
        ServerProvider::Aws,
    ];

    public static function enabled(): bool
    {
        if (! config('server_provision_fake.env_flag')) {
            return false;
        }

        return in_array(app()->environment(), config('server_provision_fake.allowed_environments', []), true);
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

        return (string) $server->provider_id === self::sentinelProviderId();
    }

    public static function shouldInterceptVmProvision(Server $server): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return in_array($server->provider, self::VM_POLL_PROVIDERS, true);
    }

    public static function shouldInterceptFlyIoUiStub(Server $server): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (! config('server_provision_fake.fly_io_ui_stub')) {
            return false;
        }

        return $server->provider === ServerProvider::FlyIo;
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
