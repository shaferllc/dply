<?php

namespace App\Livewire\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\Script;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public ?string $operationMessage = null;

    public ?string $operationError = null;

    public function mount(): void
    {
        Gate::authorize('viewPlatformAdmin');
    }

    public function clearApplicationCache(): void
    {
        Gate::authorize('viewPlatformAdmin');
        $this->resetOperationFlash();
        try {
            Artisan::call('cache:clear');
            $this->operationMessage = __('Application cache cleared.');
        } catch (\Throwable $e) {
            $this->operationError = $e->getMessage();
        }
    }

    public function clearOptimizedCaches(): void
    {
        Gate::authorize('viewPlatformAdmin');
        $this->resetOperationFlash();
        try {
            Artisan::call('optimize:clear');
            $this->operationMessage = __('Optimize clear finished (config, route, view, event caches as applicable).');
        } catch (\Throwable $e) {
            $this->operationError = $e->getMessage();
        }
    }

    public function downloadAuditCsv(): StreamedResponse
    {
        Gate::authorize('viewPlatformAdmin');

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'created_at', 'action', 'user_email', 'organization', 'subject_type', 'subject_id', 'subject_summary']);
            AuditLog::query()
                ->with(['subject', 'user:id,email', 'organization:id,name'])
                ->orderByDesc('id')
                ->chunk(500, function ($logs) use ($out) {
                    foreach ($logs as $log) {
                        fputcsv($out, [
                            $log->id,
                            $log->created_at?->toIso8601String(),
                            $log->action,
                            $log->user?->email,
                            $log->organization?->name,
                            $log->subject_type,
                            $log->subject_id,
                            $log->subject_summary,
                        ]);
                    }
                });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadUsersCsv(): StreamedResponse
    {
        Gate::authorize('viewPlatformAdmin');

        $filename = 'users-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'name', 'email', 'email_verified_at', 'created_at', 'two_factor_confirmed_at']);
            User::query()
                ->orderBy('email')
                ->chunk(500, function ($users) use ($out) {
                    foreach ($users as $u) {
                        fputcsv($out, [
                            $u->id,
                            $u->name,
                            $u->email,
                            $u->email_verified_at?->toIso8601String(),
                            $u->created_at?->toIso8601String(),
                            $u->two_factor_confirmed_at?->toIso8601String(),
                        ]);
                    }
                });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        Gate::authorize('viewPlatformAdmin');

        $since = now()->subDay();
        $since7d = now()->subDays(7);
        $base = base_path('bootstrap/cache');
        $routeCaches = glob($base.'/routes-*.php') ?: [];

        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        $redisOk = null;
        $redisMessage = null;
        try {
            $redisOk = (bool) Redis::connection()->ping();
        } catch (\Throwable $e) {
            $redisOk = false;
            $redisMessage = $e->getMessage();
        }

        $pendingJobs = 0;
        $failedJobsCount = 0;
        if (Schema::hasTable('jobs')) {
            $pendingJobs = (int) DB::table('jobs')->count();
        }
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsCount = (int) DB::table('failed_jobs')->count();
        }

        $recentFailedJobs = collect();
        if (Schema::hasTable('failed_jobs')) {
            $recentFailedJobs = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(12)
                ->get(['id', 'uuid', 'connection', 'queue', 'failed_at']);
        }

        $logTail = $this->readLogTail(storage_path('logs/laravel.log'), 36);

        $serverByStatus = Server::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $siteByStatus = Site::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $taskRunnerFailed = TaskRunnerTask::query()
            ->whereIn('status', TaskStatus::getFailedStatuses())
            ->count();

        $taskRunnerRunning = TaskRunnerTask::query()->where('status', TaskStatus::Running)->count();

        return view('livewire.admin.dashboard', [
            'featuresHighlight' => [
                __('Runtime & environment snapshot'),
                __('Optimization cache indicators'),
                __('Database and Redis connectivity checks'),
                __('Queue depth and recent failed jobs'),
                __('Task runner health (pending, failed, running)'),
                __('Infrastructure drivers (queue, cache, broadcast, mail)'),
                __('Disk space for storage'),
                __('Server and site status breakdowns'),
                __('7-day growth (users and organizations)'),
                __('Pending invitations and API token inventory'),
                __('Status pages and scripts inventory'),
                __('In-app scheduler visibility'),
                __('CSV export for audit log'),
                __('CSV export for user directory'),
                __('One-click application cache clear'),
                __('One-click optimize:clear'),
                __('Live application log tail preview'),
                __('Top organizations by connected servers'),
                __('Expanded operations shortcuts (Horizon, Pulse, Reverb)'),
                __('Larger audit context with operational runbook notes'),
            ],
            'counts' => [
                'users' => User::query()->count(),
                'organizations' => Organization::query()->count(),
                'servers' => Server::query()->count(),
                'sites' => Site::query()->count(),
                'audit_logs_24h' => AuditLog::query()->where('created_at', '>=', $since)->count(),
                'task_runner_tasks_pending' => TaskRunnerTask::query()->where('status', TaskStatus::Pending)->count(),
                'users_7d' => User::query()->where('created_at', '>=', $since7d)->count(),
                'organizations_7d' => Organization::query()->where('created_at', '>=', $since7d)->count(),
                'api_tokens' => ApiToken::query()->count(),
                'invitations_open' => OrganizationInvitation::query()->where('expires_at', '>', now())->count(),
                'status_pages' => StatusPage::query()->count(),
                'scripts' => Script::query()->count(),
                'projects' => Project::query()->count(),
                'task_runner_failed' => $taskRunnerFailed,
                'task_runner_running' => $taskRunnerRunning,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobsCount,
            ],
            'system' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'env' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'maintenance' => app()->isDownForMaintenance(),
                'url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'config_cached' => is_file($base.'/config.php'),
                'routes_cached' => $routeCaches !== [],
                'events_cached' => is_file($base.'/events.php'),
                'db_ok' => $dbOk,
                'redis_ok' => $redisOk,
                'redis_error' => $redisMessage,
                'queue_connection' => config('queue.default'),
                'cache_store' => config('cache.default'),
                'broadcast' => config('broadcasting.default'),
                'mail_mailer' => config('mail.default'),
                'disk' => $this->diskSummary(storage_path()),
            ],
            'serverByStatus' => $serverByStatus,
            'siteByStatus' => $siteByStatus,
            'recentAuditLogs' => AuditLog::query()
                ->with(['subject', 'user:id,name,email', 'organization:id,name'])
                ->latest('created_at')
                ->limit(40)
                ->get(),
            'recentUsers' => User::query()
                ->latest('created_at')
                ->limit(12)
                ->get(['id', 'name', 'email', 'created_at']),
            'topOrganizations' => Organization::query()
                ->withCount('servers')
                ->orderByDesc('servers_count')
                ->limit(6)
                ->get(['id', 'name', 'servers_count']),
            'scheduleEntries' => $this->scheduleEntries(),
            'recentFailedJobs' => $recentFailedJobs,
            'logTail' => $logTail,
            'reverbHealthUrl' => reverb_health_check_url(),
            'horizonUrl' => route('horizon.index'),
            'pulseUrl' => route('pulse'),
        ]);
    }

    protected function resetOperationFlash(): void
    {
        $this->operationMessage = null;
        $this->operationError = null;
    }

    /**
     * @return list<array{label: string, cadence: string}>
     */
    protected function scheduleEntries(): array
    {
        return [
            ['label' => __('Dispatch health checks for reachable servers'), 'cadence' => __('Every 5 minutes')],
            ['label' => __('Dispatch URL health checks for active sites (when enabled)'), 'cadence' => __('Every 10 minutes')],
            ['label' => __('Deploy digest email flush'), 'cadence' => __('Hourly (when digest hours > 0)')],
            ['label' => __('Process scheduled server deletions'), 'cadence' => __('Every minute')],
        ];
    }

    protected function diskSummary(string $path): ?array
    {
        if (! is_dir($path)) {
            return null;
        }
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }
        $used = $total - $free;
        $pct = round(100 * ($used / $total), 1);

        return [
            'free' => Number::fileSize($free, 1),
            'total' => Number::fileSize($total, 1),
            'used_percent' => $pct,
            'path' => $path,
        ];
    }

    protected function readLogTail(string $path, int $lines): ?string
    {
        if (! is_readable($path)) {
            return null;
        }
        try {
            $content = file_get_contents($path, false, null, max(0, filesize($path) - 98_000));
            if ($content === false) {
                return null;
            }
            $parts = preg_split("/\r\n|\n|\r/", $content) ?: [];
            $tail = array_slice($parts, -$lines);

            return Str::limit(implode("\n", $tail), 12000);
        } catch (\Throwable) {
            return null;
        }
    }
}
