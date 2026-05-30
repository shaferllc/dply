<?php

declare(strict_types=1);

use App\Services\Servers\LiveState\EngineLiveState;

it('normalizes string probe errors for display', function (): void {
    expect(EngineLiveState::probeErrorLines('Caddy admin API unreachable'))
        ->toBe(['Caddy admin API unreachable']);
});

it('normalizes list probe errors for display', function (): void {
    expect(EngineLiveState::probeErrorLines([
        'Traefik API SSH exited 1',
        'Connection refused',
    ]))->toBe([
        'Traefik API SSH exited 1',
        'Connection refused',
    ]);
});

it('flattens nested probe error objects without array to string conversion', function (): void {
    expect(EngineLiveState::probeErrorLines([
        ['message' => 'Envoy admin API SSH exited 2'],
        ['endpoint' => '/clusters', 'status' => 503],
    ]))->toBe([
        'Envoy admin API SSH exited 2',
        '{"endpoint":"/clusters","status":503}',
    ]);
});

it('returns no lines for empty probe errors', function (): void {
    expect(EngineLiveState::probeErrorLines(null))->toBe([])
        ->and(EngineLiveState::probeErrorLines([]))->toBe([])
        ->and(EngineLiveState::probeErrorLines(''))->toBe([]);
});
