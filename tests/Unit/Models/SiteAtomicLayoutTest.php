<?php

declare(strict_types=1);

use App\Models\Site;

test('site reports converting atomic layout from meta status', function () {
    $site = new Site([
        'deploy_strategy' => 'simple',
        'meta' => ['atomic_layout' => ['status' => 'converting']],
    ]);

    expect(method_exists($site, 'isConvertingAtomicLayout'))->toBeTrue()
        ->and($site->isConvertingAtomicLayout())->toBeTrue()
        ->and($site->isAtomicDeploys())->toBeFalse()
        ->and($site->isDisablingAtomicLayout())->toBeFalse();
});

test('site is not converting atomic layout without conversion status', function () {
    $simple = new Site(['deploy_strategy' => 'simple']);
    $atomic = new Site(['deploy_strategy' => 'atomic']);
    $failed = new Site([
        'deploy_strategy' => 'simple',
        'meta' => ['atomic_layout' => ['status' => 'failed']],
    ]);

    expect($simple->isConvertingAtomicLayout())->toBeFalse()
        ->and($atomic->isConvertingAtomicLayout())->toBeFalse()
        ->and($atomic->isAtomicDeploys())->toBeTrue()
        ->and($failed->isConvertingAtomicLayout())->toBeFalse();
});

test('disabling zero-downtime is not converting atomic layout', function () {
    $site = new Site([
        'deploy_strategy' => 'atomic',
        'meta' => [
            'atomic_layout' => ['status' => 'disabling'],
            'deploy_layout_migration' => ['from' => 'atomic', 'to' => 'flat'],
        ],
    ]);

    expect($site->isConvertingAtomicLayout())->toBeFalse()
        ->and($site->isDisablingAtomicLayout())->toBeTrue();
});
