<?php

namespace Tests\Unit\Services\ServerSystemdServiceSnapshotDiffTest;

use App\Services\Servers\ServerSystemdServiceSnapshotDiff;

it('emits no events without prior snapshot', function () {
    $diff = new ServerSystemdServiceSnapshotDiff;

    expect($diff->diff(null, [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
    ]))->toBe([]);
});

it('detects stopped when unit disappears from running list', function () {
    $diff = new ServerSystemdServiceSnapshotDiff;
    $old = [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
    ];
    $new = [];

    $events = $diff->diff($old, $new);

    expect($events)->toHaveCount(1);
    expect($events[0]['kind'])->toBe('stopped');
    expect($events[0]['unit'])->toBe('nginx.service');
});

it('detects started for new running unit', function () {
    $diff = new ServerSystemdServiceSnapshotDiff;
    $old = [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
    ];
    $new = [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
        ['unit' => 'redis-server.service', 'label' => 'redis-server', 'active' => 'active', 'sub' => 'running', 'ts' => 't2', 'version' => '1'],
    ];

    $events = $diff->diff($old, $new);

    $kinds = array_column($events, 'kind');
    expect($kinds)->toContain('started');
    expect(collect($events)->contains(fn ($e) => $e['unit'] === 'redis-server.service'))->toBeTrue();
});

it('detects restarted when active timestamp changes', function () {
    $diff = new ServerSystemdServiceSnapshotDiff;
    $old = [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 'Mon 2026-01-01', 'version' => '1'],
    ];
    $new = [
        ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 'Mon 2026-01-02', 'version' => '1'],
    ];

    $events = $diff->diff($old, $new);

    expect($events)->toHaveCount(1);
    expect($events[0]['kind'])->toBe('restarted');
});
