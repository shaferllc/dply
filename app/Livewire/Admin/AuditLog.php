<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class AuditLog extends Component
{
    use AuthorizesPlatformAdmin;
    use WithPagination;

    #[Url(as: 'action', except: '')]
    public string $actionFilter = '';

    #[Url(as: 'org', except: '')]
    public string $organizationFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedOrganizationFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function downloadCsv(): StreamedResponse
    {
        Gate::authorize('viewPlatformAdmin');

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'created_at', 'action', 'user_email', 'organization', 'subject_type', 'subject_id', 'subject_summary']);
            $this->auditQuery()
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

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $logs = $this->auditQuery()
            ->latest('created_at')
            ->paginate(25);

        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('livewire.admin.audit-log', [
            'logs' => $logs,
            'actions' => $actions,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @return Builder<AuditLog>
     */
    protected function auditQuery()
    {
        $query = AuditLog::query()
            ->with(['subject', 'user:id,name,email', 'organization:id,name']);

        if ($this->actionFilter !== '') {
            $query->where('action', $this->actionFilter);
        }

        if ($this->organizationFilter !== '') {
            $query->where('organization_id', $this->organizationFilter);
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', $term)
                    ->orWhereHas('user', fn ($uq) => $uq->where('email', 'like', $term)->orWhere('name', 'like', $term))
                    ->orWhereHas('organization', fn ($oq) => $oq->where('name', 'like', $term));
            });
        }

        return $query;
    }
}
