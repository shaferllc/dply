<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Admin\AdminPlatformMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Operations extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;

    /** Accumulated console output of operations run on this page (latest at top). */
    public string $consoleOutput = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    /**
     * Run a local artisan command, capture its output into the on-page console,
     * and toast. Synchronous (web request) — these are quick maintenance ops.
     *
     * @param  array<string, mixed>  $args
     */
    protected function runConsoleOp(string $label, string $command, array $args, string $successMsg): void
    {
        $this->authorizePlatformAdmin();

        try {
            $exit = Artisan::call($command, $args);
            $output = trim(Artisan::output());
            $this->appendConsole($label, $command.($args !== [] ? ' '.json_encode($args) : ''), $output, $exit);
            $exit === 0 ? $this->toastSuccess($successMsg) : $this->toastError($label.' '.__('exited with code :code', ['code' => $exit]));
        } catch (\Throwable $e) {
            $this->appendConsole($label, $command, $e->getMessage(), 1);
            $this->toastError($e->getMessage());
        }
    }

    protected function appendConsole(string $label, string $command, string $output, int $exit): void
    {
        $header = sprintf("$ %s   [%s · exit %d]", $command, $label, $exit);
        $body = $output !== '' ? $output : '(no output)';
        $this->consoleOutput = trim($header."\n".$body."\n\n".$this->consoleOutput);
        // Cap so the property stays small across many runs.
        if (mb_strlen($this->consoleOutput) > 20000) {
            $this->consoleOutput = mb_substr($this->consoleOutput, 0, 20000)."\n…(truncated)";
        }
    }

    public function clearConsole(): void
    {
        $this->consoleOutput = '';
    }

    public function clearApplicationCache(): void
    {
        $this->runConsoleOp(__('Clear cache'), 'cache:clear', [], __('Application cache cleared.'));
    }

    public function clearOptimizedCaches(): void
    {
        $this->runConsoleOp(__('Optimize clear'), 'optimize:clear', [], __('Optimize clear finished (config, route, view, event caches as applicable).'));
    }

    public function retryFailedJobs(): void
    {
        $this->runConsoleOp(__('Retry failed jobs'), 'queue:retry', ['id' => ['all']], __('Failed jobs queued for retry.'));
    }

    public function flushFailedJobs(): void
    {
        $this->runConsoleOp(__('Flush failed jobs'), 'queue:flush', [], __('Failed jobs cleared.'));
    }

    public function downloadAuditCsv(): StreamedResponse
    {
        $this->authorizePlatformAdmin();

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
        $this->authorizePlatformAdmin();

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
        $this->authorizePlatformAdmin();

        return view('livewire.admin.operations', [
            'counts' => AdminPlatformMetrics::counts(),
            'system' => AdminPlatformMetrics::system(),
            'serverByStatus' => AdminPlatformMetrics::serverByStatus(),
            'siteByStatus' => AdminPlatformMetrics::siteByStatus(),
            'scheduleEntries' => AdminPlatformMetrics::scheduleEntries(),
            'recentFailedJobs' => AdminPlatformMetrics::recentFailedJobs(),
            'failedJobsCount' => DB::table('failed_jobs')->count(),
            'logTail' => AdminPlatformMetrics::readLogTail(storage_path('logs/laravel.log')),
            'reverbHealthUrl' => reverb_health_check_url(),
            'horizonUrl' => route('horizon.index'),
            'pulseUrl' => route('pulse'),
        ]);
    }
}
