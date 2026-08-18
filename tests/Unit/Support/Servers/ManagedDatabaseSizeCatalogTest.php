<?php

declare(strict_types=1);

use App\Support\Servers\ManagedDatabaseSizeCatalog;

test('size options collapse intel and amd twins onto the priced basic slug', function () {
    $options = ManagedDatabaseSizeCatalog::optionsFromSlugs([
        'db-s-1vcpu-1gb',
        'db-s-1vcpu-2gb',
        'db-s-2vcpu-4gb',
        'db-s-4vcpu-8gb',
        'db-s-6vcpu-16gb',
        'db-s-8vcpu-32gb',
        'db-s-16vcpu-64gb',
        'db-intel-1vcpu-1gb',
        'db-intel-1vcpu-2gb',
        'db-intel-2vcpu-4gb',
        'db-intel-2vcpu-8gb',
        'db-amd-1vcpu-1gb',
        'db-amd-1vcpu-2gb',
        'db-amd-4vcpu-16gb',
        'm-2vcpu-16gb',
    ]);

    $labels = array_column($options, 'label');

    expect($labels)->toBe([
        '1 vCPU / 1 GB · ~$15/mo',
        '1 vCPU / 2 GB · ~$30/mo',
        '2 vCPU / 4 GB · ~$60/mo',
        '2 vCPU / 8 GB',
        '4 vCPU / 8 GB · ~$120/mo',
        '4 vCPU / 16 GB',
        '6 vCPU / 16 GB · ~$240/mo',
        '8 vCPU / 32 GB · ~$480/mo',
        '16 vCPU / 64 GB · ~$960/mo',
        '2 vCPU / 16 GB',
    ]);

    $byValue = collect($options)->keyBy('value');
    expect($byValue->has('db-s-1vcpu-1gb'))->toBeTrue()
        ->and($byValue->has('db-intel-1vcpu-1gb'))->toBeFalse()
        ->and($byValue->has('db-amd-1vcpu-1gb'))->toBeFalse()
        ->and($byValue['m-2vcpu-16gb']['group'])->toBe(__('Memory-optimized'));
});

test('larger basic plans have a monthly estimate', function () {
    expect(ManagedDatabaseSizeCatalog::monthlyUsd('db-s-6vcpu-16gb'))->toBe(240)
        ->and(ManagedDatabaseSizeCatalog::monthlyUsd('db-s-8vcpu-32gb'))->toBe(480)
        ->and(ManagedDatabaseSizeCatalog::monthlyUsd('db-s-16vcpu-64gb'))->toBe(960)
        ->and(ManagedDatabaseSizeCatalog::label('db-s-6vcpu-16gb'))->toContain('~$240/mo');
});
