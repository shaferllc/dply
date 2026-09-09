<?php

declare(strict_types=1);

use App\Models\SiteBinding;

test('managed redis provisioned by dply can be deleted on detach', function (): void {
    $binding = new SiteBinding([
        'type' => 'redis',
        'mode' => 'provision_new',
        'target_type' => 'cloud_database',
        'config' => ['placement' => 'managed', 'managed' => true],
    ]);

    expect($binding->canOfferDeleteOnDetach())->toBeTrue()
        ->and($binding->deleteOnDetachLabel())->toBe(__('Also delete the managed Valkey cluster'))
        ->and($binding->deleteOnDetachHint())->toContain('managed cluster');
});

test('attached existing redis is never deleted on detach', function (): void {
    $binding = new SiteBinding([
        'type' => 'redis',
        'mode' => 'attach',
        'target_type' => 'cloud_database',
        'config' => ['placement' => 'managed', 'managed' => true],
    ]);

    expect($binding->canOfferDeleteOnDetach())->toBeFalse()
        ->and($binding->deleteOnDetachLabel())->toBeNull();
});

test('dedicated redis vm provisioned by dply can be destroyed on detach', function (): void {
    $binding = new SiteBinding([
        'type' => 'redis',
        'mode' => 'provision_new',
        'target_type' => 'server_cache_service',
        'config' => ['placement' => 'cache_vm', 'cache_vm_server_id' => 'cache-1'],
    ]);

    expect($binding->deleteOnDetachLabel())->toBe(__('Also destroy the dedicated Redis server'));
});
