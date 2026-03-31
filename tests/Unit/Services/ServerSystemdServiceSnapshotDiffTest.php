<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerSystemdServiceSnapshotDiff;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerSystemdServiceSnapshotDiffTest extends TestCase
{
    #[Test]
    public function it_emits_no_events_without_prior_snapshot(): void
    {
        $diff = new ServerSystemdServiceSnapshotDiff;

        $this->assertSame([], $diff->diff(null, [
            ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
        ]));
    }

    #[Test]
    public function it_detects_stopped_when_unit_disappears_from_running_list(): void
    {
        $diff = new ServerSystemdServiceSnapshotDiff;
        $old = [
            ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 't1', 'version' => '1'],
        ];
        $new = [];

        $events = $diff->diff($old, $new);

        $this->assertCount(1, $events);
        $this->assertSame('stopped', $events[0]['kind']);
        $this->assertSame('nginx.service', $events[0]['unit']);
    }

    #[Test]
    public function it_detects_started_for_new_running_unit(): void
    {
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
        $this->assertContains('started', $kinds);
        $this->assertTrue(collect($events)->contains(fn ($e) => $e['unit'] === 'redis-server.service'));
    }

    #[Test]
    public function it_detects_restarted_when_active_timestamp_changes(): void
    {
        $diff = new ServerSystemdServiceSnapshotDiff;
        $old = [
            ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 'Mon 2026-01-01', 'version' => '1'],
        ];
        $new = [
            ['unit' => 'nginx.service', 'label' => 'nginx', 'active' => 'active', 'sub' => 'running', 'ts' => 'Mon 2026-01-02', 'version' => '1'],
        ];

        $events = $diff->diff($old, $new);

        $this->assertCount(1, $events);
        $this->assertSame('restarted', $events[0]['kind']);
    }
}
