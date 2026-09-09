<?php

declare(strict_types=1);

use App\Models\Site;
use App\Services\Sites\SiteAtomicLayoutConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteShell;

uses(RefreshDatabase::class);

test('inspect classifies an empty checkout', function () {
    $ssh = new FakeRemoteShell(fn (string $command): ?string => str_contains($command, 'DPLY_LAYOUT')
        ? "DPLY_LAYOUT=empty\nDPLY_CURRENT=\nDPLY_GIT_SHA=\nDPLY_HAS_ENV=0\nDPLY_HAS_STORAGE=0\n"
        : null);

    $info = (new SiteAtomicLayoutConverter)->inspect($ssh, '/home/dply/app');

    expect($info['layout'])->toBe(SiteAtomicLayoutConverter::LAYOUT_EMPTY)
        ->and($info['has_env'])->toBeFalse();
});

test('inspect classifies hybrid leftover next to current', function () {
    $ssh = new FakeRemoteShell(fn (string $command): ?string => str_contains($command, 'DPLY_LAYOUT')
        ? "DPLY_LAYOUT=hybrid\nDPLY_CURRENT=/home/dply/app/releases/20260101120000\nDPLY_GIT_SHA=abc123\nDPLY_HAS_ENV=1\nDPLY_HAS_STORAGE=1\n"
        : null);

    $info = (new SiteAtomicLayoutConverter)->inspect($ssh, '/home/dply/app');

    expect($info['layout'])->toBe(SiteAtomicLayoutConverter::LAYOUT_HYBRID)
        ->and($info['current'])->toEndWith('20260101120000')
        ->and($info['has_env'])->toBeTrue()
        ->and($info['has_storage'])->toBeTrue();
});

test('flat convert copies into releases then points current', function () {
    $ssh = new FakeRemoteShell(function (string $command): ?string {
        if (str_contains($command, 'DPLY_LAYOUT=')) {
            return "DPLY_LAYOUT=flat\nDPLY_CURRENT=\nDPLY_GIT_SHA=deadbeef\nDPLY_HAS_ENV=1\nDPLY_HAS_STORAGE=1\n";
        }

        return "[dply] ok\n";
    });

    $site = Site::factory()->create([
        'deploy_strategy' => 'simple',
        'repository_path' => '/home/dply/app',
    ]);

    $result = (new SiteAtomicLayoutConverter)->convert($site, $ssh);

    expect($result['skipped'])->toBeFalse()
        ->and($result['layout'])->toBe(SiteAtomicLayoutConverter::LAYOUT_FLAT)
        ->and($result['folder'])->not->toBeEmpty();

    $joined = collect($ssh->execCalls)->pluck(0)->implode("\n");
    expect($joined)->toContain('cp -a')
        ->and($joined)->toContain('shared/.env')
        ->and($joined)->toContain('ln -sfn');
});
