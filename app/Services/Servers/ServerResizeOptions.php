<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Servers\Resize\AwsEc2ResizeDriver;
use App\Services\Servers\Resize\DigitalOceanResizeDriver;
use App\Services\Servers\Resize\HetznerResizeDriver;
use App\Services\Servers\Resize\ServerResizeDriver;
use App\Services\Servers\Resize\VultrResizeDriver;

/**
 * Entry point for server resizes: picks the right provider driver and answers
 * "can this be resized", "to what", and "is this target legal".
 *
 * Providers that have no driver here still can't be resized from dply — those
 * resizes happen in the provider console and are reconciled afterwards with
 * {@see ServerProviderSpecSync}.
 *
 * The per-provider rules live in the drivers; what is common to all of them —
 * a disk never shrinks, the current size is not a target, re-validate the
 * chosen slug server-side — is enforced here and in
 * {@see \App\Services\Servers\Resize\Concerns\BuildsResizeOptions}.
 */
class ServerResizeOptions
{
    /** @var list<class-string<ServerResizeDriver>> */
    private const DRIVERS = [
        DigitalOceanResizeDriver::class,
        HetznerResizeDriver::class,
        VultrResizeDriver::class,
        AwsEc2ResizeDriver::class,
    ];

    /** Whether this server can be resized from inside dply at all. */
    public function supports(Server $server): bool
    {
        return $this->driverFor($server) !== null;
    }

    /** The driver handling this server, or null when no provider driver claims it. */
    public function driverFor(Server $server): ?ServerResizeDriver
    {
        foreach (self::DRIVERS as $class) {
            $driver = app($class);
            if ($driver->supports($server)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * Current machine facts plus every legal target, smallest first.
     *
     * @return array{current: array<string, mixed>, options: list<array<string, mixed>>}
     *
     * @throws \RuntimeException when the server is not resizable or the provider call fails
     */
    public function forServer(Server $server): array
    {
        return $this->requireDriver($server)->catalog($server);
    }

    /** Whether the machine goes fully offline for this provider's resize. */
    public function requiresPowerCycle(Server $server): bool
    {
        return $this->requireDriver($server)->requiresPowerCycle();
    }

    /**
     * Resolve one target slug against the legal set.
     *
     * Re-derived server-side rather than trusting the posted row — the picker
     * is a convenience, this is the gate.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the slug is not a legal target for this server
     */
    public function resolveTarget(Server $server, string $slug): array
    {
        $slug = trim($slug);

        foreach ($this->forServer($server)['options'] as $option) {
            if ($option['slug'] === $slug) {
                return $option;
            }
        }

        throw new \RuntimeException(sprintf('"%s" is not a size this server can resize to.', $slug));
    }

    private function requireDriver(Server $server): ServerResizeDriver
    {
        return $this->driverFor($server)
            ?? throw new \RuntimeException('This server cannot be resized from dply.');
    }
}
