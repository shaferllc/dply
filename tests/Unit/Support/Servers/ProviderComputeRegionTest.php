<?php

declare(strict_types=1);

use App\Support\Servers\ProviderComputeRegion;

test('digitalocean short city codes map to a current datacenter', function (): void {
    expect(ProviderComputeRegion::normalize('digitalocean', 'sfo'))->toBe('sfo3')
        ->and(ProviderComputeRegion::normalize('digitalocean', 'nyc'))->toBe('nyc3')
        ->and(ProviderComputeRegion::normalize('digitalocean', 'sfo2'))->toBe('sfo2');
});

test('an unavailable numbered region remaps to the newest sibling', function (): void {
    expect(ProviderComputeRegion::coerceAvailable('digitalocean', 'sfo2', ['nyc1', 'sfo3', 'sfo1']))
        ->toBe('sfo3');
});

test('a valid region is left alone', function (): void {
    expect(ProviderComputeRegion::coerceAvailable('digitalocean', 'sfo2', ['sfo2', 'sfo3']))
        ->toBe('sfo2');
});
