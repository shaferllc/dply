<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FeedbackReport;
use App\Modules\Feedback\Notifications\FeedbackReportStatusChanged;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: 'all')]
    public string $typeFilter = 'all';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'severity', except: 'all')]
    public string $severityFilter = 'all';

    #[Url(as: 'report', except: '')]
    public string $selectedId = '';

    // Triage form (bound while the detail modal is open).
    public string $triageStatus = '';

    public string $triageAssignee = '';

    public string $triageNotes = '';

    public bool $notifyReporter = false;

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();

        if ($this->selectedId !== '') {
            $this->openReport($this->selectedId);
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSeverityFilter(): void
    {
        $this->resetPage();
    }

    public function openReport(string $id): void
    {
        $this->authorizePlatformAdmin();

        $report = FeedbackReport::query()->find($id);

        if ($report === null) {
            $this->selectedId = '';

            return;
        }

        $this->selectedId = $report->id;
        $this->triageStatus = $report->status;
        $this->triageAssignee = (string) ($report->assigned_to_user_id ?? '');
        $this->triageNotes = (string) ($report->admin_notes ?? '');
        $this->notifyReporter = false;
    }

    public function closeReport(): void
    {
        $this->reset(['selectedId', 'triageStatus', 'triageAssignee', 'triageNotes', 'notifyReporter']);
    }

    public function saveTriage(): void
    {
        $this->authorizePlatformAdmin();

        $report = FeedbackReport::query()->find($this->selectedId);
        if ($report === null) {
            $this->toastError(__('That report no longer exists.'));
            $this->closeReport();

            return;
        }

        $validated = $this->validate([
            'triageStatus' => ['required', 'string', 'in:'.implode(',', FeedbackReport::statusKeys())],
            'triageAssignee' => ['nullable', 'string'],
            'triageNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $previousStatus = $report->status;
        $newStatus = $validated['triageStatus'];

        $report->status = $newStatus;
        $report->assigned_to_user_id = $validated['triageAssignee'] !== '' ? $validated['triageAssignee'] : null;
        $report->admin_notes = $validated['triageNotes'] !== '' ? $validated['triageNotes'] : null;

        if (in_array($newStatus, FeedbackReport::TERMINAL_STATUSES, true)) {
            $report->resolved_at ??= now();
        } else {
            $report->resolved_at = null;
        }

        $report->save();

        // Loop the reporter back in (bell only) when an admin opts in and the
        // report just reached a resolved/won't-fix state.
        $closingStatuses = [FeedbackReport::STATUS_RESOLVED, FeedbackReport::STATUS_WONT_FIX];
        if (
            $this->notifyReporter
            && $newStatus !== $previousStatus
            && in_array($newStatus, $closingStatuses, true)
            && $report->user !== null
        ) {
            $report->user->notify(new FeedbackReportStatusChanged($report, $report->admin_notes));
        }

        $this->toastSuccess(__('Report :ref updated.', ['ref' => $report->reference]));
        $this->closeReport();
    }

    public function render(): View
    {
        $reports = FeedbackReport::query()
            ->with(['user', 'organization', 'assignee'])
            ->search($this->search)
            ->type($this->typeFilter)
            ->status($this->statusFilter)
            ->severity($this->severityFilter)
            ->latest()
            ->paginate(20);

        $selected = $this->selectedId !== ''
            ? FeedbackReport::query()->with(['user', 'organization', 'assignee'])->find($this->selectedId)
            : null;

        return view('livewire.admin.feedback.index', [
            'reports' => $reports,
            'selected' => $selected,
            'admins' => PlatformAdmins::users(),
            'newCount' => FeedbackReport::query()->where('status', FeedbackReport::STATUS_NEW)->count(),
            'types' => config('feedback.types', []),
            'statuses' => config('feedback.statuses', []),
            'severities' => config('feedback.severities', []),
        ]);
    }
}
