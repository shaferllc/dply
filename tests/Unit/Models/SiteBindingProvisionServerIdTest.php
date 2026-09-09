<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\SiteBinding;

test('cache_vm bindings resolve the redis host id', function (): void {
    $binding = new SiteBinding([
        'config' => [
            'placement' => 'cache_vm',
            'cache_vm_server_id' => 'cache-host-1',
            'db_vm_server_id' => 'ignored-db',
        ],
    ]);

    expect($binding->provisionServerId())->toBe('cache-host-1');
});

test('dedicated and docker vm bindings resolve the database host id', function (string $placement): void {
    $binding = new SiteBinding([
        'config' => [
            'placement' => $placement,
            'db_vm_server_id' => 'db-host-1',
            'cache_vm_server_id' => 'ignored-cache',
        ],
    ]);

    expect($binding->provisionServerId())->toBe('db-host-1');
})->with(['dedicated_vm', 'docker_vm']);

test('managed placements have no provision server', function (): void {
    $binding = new SiteBinding([
        'config' => ['managed' => true, 'placement' => 'managed'],
    ]);

    expect($binding->provisionServerId())->toBeNull();
});

test('display error prefers the binding message and appends the provider error', function (): void {
    $binding = new SiteBinding([
        'status' => SiteBinding::STATUS_ERROR,
        'last_error' => 'The Redis server failed to provision.',
    ]);
    $server = new Server([
        'meta' => ['provision_error' => ['message' => 'size is not available in this region']],
    ]);

    expect($binding->displayError($server))->toBe(
        'The Redis server failed to provision. — size is not available in this region'
    );
});

test('display error does not duplicate the same provider message', function (): void {
    $binding = new SiteBinding([
        'status' => SiteBinding::STATUS_ERROR,
        'last_error' => 'The Redis server failed to provision. — size is not available in this region',
    ]);
    $server = new Server([
        'meta' => ['provision_error' => ['message' => 'size is not available in this region']],
    ]);

    expect($binding->displayError($server))->toBe(
        'The Redis server failed to provision. — size is not available in this region'
    );
});
