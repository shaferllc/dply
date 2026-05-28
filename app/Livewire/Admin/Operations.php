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
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Operations extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function clearApplicationCache(): void
    {
        $this->authorizePlatformAdmin();

        try {
            Artisan::call('cache:clear');
            $this->toastSuccess(__('Application cache cleared.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function clearOptimizedCaches(): void
    {
        $this->authorizePlatformAdmin();

        try {
            Artisan::call('optimize:clear');
            $this->toastSuccess(__('Optimize clear finished (config, route, view, event caches as applicable).'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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
            'logTail' => AdminPlatformMetrics::readLogTail(storage_path('logs/laravel.log')),
            'reverbHealthUrl' => reverb_health_check_url(),
            'horizonUrl' => route('horizon.index'),
            'pulseUrl' => route('pulse'),
        ]);
    }
}
