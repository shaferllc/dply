<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cross-framework scheduler view: surfaces the cron entries and supervisor
 * programs that look like framework schedulers (Laravel `schedule:run`,
 * Rails `whenever`, Celery `beat`, etc.). It does not own the data — it's a
 * filtered window onto the existing Cron and Daemons pages.
 */
#[Layout('layouts.app')]
class WorkspaceSchedule extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Sub-strings that mark a cron entry as a framework scheduler. */
    private const SCHEDULER_PATTERNS = [
        'schedule:run',
        'schedule:work',
        'whenever',
        'bin/rails runner',
        'celery beat',
        'celerybeat',
        'rake schedule',
    ];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        $this->server->refresh();

        $cronEntries = ServerCronJob::query()
            ->where('server_id', $this->server->id)
            ->get()
            ->filter(fn (ServerCronJob $job): bool => $this->commandLooksLikeScheduler((string) $job->command))
            ->values();

        $schedulerDaemons = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where(function ($q) {
                foreach (self::SCHEDULER_PATTERNS as $needle) {
                    $q->orWhere('command', 'like', '%'.$needle.'%');
                }
            })
            ->orderBy('slug')
            ->get();

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        return view('livewire.servers.workspace-schedule', [
            'opsReady' => $this->serverOpsReady(),
            'cronEntries' => $cronEntries,
            'schedulerDaemons' => $schedulerDaemons,
            'sites' => $sites,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    private function commandLooksLikeScheduler(string $command): bool
    {
        $lc = Str::lower($command);
        foreach (self::SCHEDULER_PATTERNS as $needle) {
            if (Str::contains($lc, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }
}
