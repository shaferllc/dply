<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Livewire\TaskMetricsDashboard;
use App\Modules\TaskRunner\Models\Task;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('TaskMetricsDashboard Livewire Component', function () {
    it('can mount with default parameters', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('timeRange', '24h')
            ->assertSet('refreshInterval', 30000)
            ->assertSet('autoRefresh', true)
            ->assertSet('showCharts', true);
    });

    it('can mount with custom time range', function () {
        Livewire::test(TaskMetricsDashboard::class, ['timeRange' => '7d'])
            ->assertSet('timeRange', '7d');
    });

    it('loads task statistics', function () {
        // Create tasks with different statuses
        Task::factory()->count(5)->create(['status' => TaskStatus::Finished]);
        Task::factory()->count(3)->create(['status' => TaskStatus::Failed]);
        Task::factory()->count(2)->create(['status' => TaskStatus::Running]);
        Task::factory()->count(1)->create(['status' => TaskStatus::Pending]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 11 &&
                       $stats['finished'] === 5 &&
                       $stats['failed'] === 3 &&
                       $stats['running'] === 2 &&
                       $stats['pending'] === 1;
            });
    });

    it('calculates success rate correctly', function () {
        Task::factory()->count(8)->create(['status' => TaskStatus::Finished]);
        Task::factory()->count(2)->create(['status' => TaskStatus::Failed]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['success_rate'] === 80.0; // 8/10 * 100
            });
    });

    it('handles zero tasks gracefully', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 0 &&
                       $stats['success_rate'] === 0.0;
            });
    });

    it('loads task trends over time', function () {
        // Create tasks with different timestamps
        Task::factory()->count(3)->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subHours(1),
        ]);
        Task::factory()->count(2)->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subHours(2),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('trends', function ($trends) {
                return is_array($trends) && ! empty($trends);
            });
    });

    it('loads performance metrics', function () {
        Task::factory()->count(5)->create([
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(3),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('performance', function ($perf) {
                return isset($perf['average_duration']) &&
                       isset($perf['fastest_task']) &&
                       isset($perf['slowest_task']);
            });
    });

    it('loads task distribution by status', function () {
        Task::factory()->count(4)->create(['status' => TaskStatus::Finished]);
        Task::factory()->count(2)->create(['status' => TaskStatus::Failed]);
        Task::factory()->count(1)->create(['status' => TaskStatus::Running]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('distribution', function ($dist) {
                return $dist['finished'] === 4 &&
                       $dist['failed'] === 2 &&
                       $dist['running'] === 1;
            });
    });

    it('loads recent activity', function () {
        $recentTask = Task::factory()->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subMinutes(5),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('recentActivity', function ($activity) {
                return count($activity) > 0;
            });
    });

    it('can change time range', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->set('timeRange', '7d')
            ->call('updateTimeRange', '7d')
            ->assertSet('timeRange', '7d');
    });

    it('can toggle auto refresh', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('autoRefresh', true)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', false)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', true);
    });

    it('can toggle charts visibility', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('showCharts', true)
            ->call('toggleCharts')
            ->assertSet('showCharts', false)
            ->call('toggleCharts')
            ->assertSet('showCharts', true);
    });

    it('can refresh metrics manually', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Finished]);

        Livewire::test(TaskMetricsDashboard::class)
            ->call('refreshMetrics')
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 1 && $stats['finished'] === 1;
            });
    });

    it('filters metrics by time range', function () {
        // Create old task
        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subDays(2),
        ]);

        // Create recent task
        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subHours(1),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->set('timeRange', '24h')
            ->call('updateTimeRange', '24h')
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 1; // Only recent task
            });
    });

    it('handles different time range formats', function () {
        $timeRanges = ['1h', '24h', '7d', '30d', '90d'];

        foreach ($timeRanges as $range) {
            Livewire::test(TaskMetricsDashboard::class)
                ->set('timeRange', $range)
                ->call('updateTimeRange', $range)
                ->assertSet('timeRange', $range);
        }
    });

    it('calculates average task duration', function () {
        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(8),
        ]);

        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(6),
            'completed_at' => now()->subMinutes(4),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('performance', function ($perf) {
                return isset($perf['average_duration']) && $perf['average_duration'] > 0;
            });
    });

    it('identifies fastest and slowest tasks', function () {
        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(9),
        ]);

        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(2),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('performance', function ($perf) {
                return isset($perf['fastest_task']) &&
                       isset($perf['slowest_task']) &&
                       $perf['fastest_task']['duration'] < $perf['slowest_task']['duration'];
            });
    });

    it('tracks task execution trends', function () {
        // Create tasks over time
        for ($i = 0; $i < 5; $i++) {
            Task::factory()->create([
                'status' => TaskStatus::Finished,
                'created_at' => now()->subHours($i),
            ]);
        }

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('trends', function ($trends) {
                return is_array($trends) && count($trends) > 0;
            });
    });

    it('handles failed task analysis', function () {
        Task::factory()->create([
            'status' => TaskStatus::Failed,
            'exit_code' => 1,
            'output' => 'Error: Command not found',
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['failed'] === 1;
            })
            ->assertSet('recentActivity', function ($activity) {
                return count($activity) > 0;
            });
    });

    it('provides real-time updates', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Pending]);

        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['pending'] === 1;
            });

        // Simulate task completion
        $task->update(['status' => TaskStatus::Finished]);

        Livewire::test(TaskMetricsDashboard::class)
            ->call('refreshMetrics')
            ->assertSet('statistics', function ($stats) {
                return $stats['finished'] === 1 && $stats['pending'] === 0;
            });
    });

    it('renders the component view', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertViewIs('task-runner::livewire.task-metrics-dashboard');
    });

    it('handles empty metrics gracefully', function () {
        Livewire::test(TaskMetricsDashboard::class)
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 0;
            })
            ->assertSet('trends', function ($trends) {
                return is_array($trends);
            })
            ->assertSet('performance', function ($perf) {
                return is_array($perf);
            })
            ->assertSet('distribution', function ($dist) {
                return is_array($dist);
            })
            ->assertSet('recentActivity', function ($activity) {
                return is_array($activity);
            });
    });

    it('updates metrics when time range changes', function () {
        // Create task outside current range
        Task::factory()->create([
            'status' => TaskStatus::Finished,
            'created_at' => now()->subDays(2),
        ]);

        Livewire::test(TaskMetricsDashboard::class)
            ->set('timeRange', '24h')
            ->call('updateTimeRange', '24h')
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 0; // Task is outside 24h range
            })
            ->set('timeRange', '7d')
            ->call('updateTimeRange', '7d')
            ->assertSet('statistics', function ($stats) {
                return $stats['total'] === 1; // Task is within 7d range
            });
    });

    it('maintains state during component lifecycle', function () {
        $component = Livewire::test(TaskMetricsDashboard::class)
            ->set('timeRange', '7d')
            ->set('autoRefresh', false)
            ->set('showCharts', false);

        $component->call('refreshMetrics')
            ->assertSet('timeRange', '7d')
            ->assertSet('autoRefresh', false)
            ->assertSet('showCharts', false);
    });
});
