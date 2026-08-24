<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize;

use App\Models\Server;

/**
 * One provider's answer to "what can this machine be resized to, and how".
 *
 * Providers disagree on nearly every detail — whether the box must be powered
 * off, whether the disk comes along, whether there is an action to poll — so
 * the driver owns the whole sequence rather than exposing steps a generic job
 * would have to orchestrate differently per provider.
 *
 * @phpstan-type ResizeOption array{
 *     slug: string,
 *     vcpus: int,
 *     memory_mb: int,
 *     disk_gb: int|null,
 *     price_monthly: float|null,
 *     grows_disk: bool,
 *     direction: 'up'|'down'|'same'
 * }
 * @phpstan-type ResizeCurrent array{
 *     slug: string|null,
 *     vcpus: int|null,
 *     memory_mb: int|null,
 *     disk_gb: int|null,
 *     region: string|null
 * }
 */
interface ServerResizeDriver
{
    /** Whether this driver handles the given server. */
    public function supports(Server $server): bool;

    /**
     * Current machine facts plus every legal target, smallest first.
     *
     * @return array{current: ResizeCurrent, options: list<ResizeOption>}
     */
    public function catalog(Server $server): array;

    /**
     * Whether the machine goes offline while this provider resizes it.
     *
     * DigitalOcean, Hetzner and EC2 all require a stopped machine. Vultr
     * reboots in place, which is disruptive but much shorter — the copy shown
     * to operators and the notifications sent to the org both key off this.
     */
    public function requiresPowerCycle(): bool;

    /**
     * Run the resize to completion.
     *
     * @param  ResizeOption  $target
     * @param  callable(string): void  $progress  called with 'powering_off' | 'resizing' | 'powering_on'
     */
    public function execute(Server $server, array $target, callable $progress): void;
}
