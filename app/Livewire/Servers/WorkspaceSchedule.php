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
use Illuminate\Support\Facades\Gate;
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

    /** Form state for "Enable scheduler for site". */
    public string $enable_site_id = '';

    public string $enable_cron_expression = '* * * * *';

    /** @var 'laravel'|'rails' Framework hint that picks the right scheduler command. */
    public string $enable_framework = 'laravel';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function enableSchedulerForSite(): void
    {
        $this->authorize('update', $this->server);

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->enable_site_id)
            ->first();
        if ($site === null) {
            $this->toastError(__('Pick a site.'));

            return;
        }

        $cron = trim($this->enable_cron_expression);
        if ($cron === '' || strlen($cron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
        $command = match ($this->enable_framework) {
            'laravel' => 'cd '.$directory.' && php artisan schedule:run >> /dev/null 2>&1',
            'rails' => 'cd '.$directory.' && bundle exec whenever --update-crontab',
            default => null,
        };

        if ($command === null) {
            $this->toastError(__('Unknown framework.'));

            return;
        }

        // Same shape that the Cron page produces — uses the existing model so this entry
        // is editable from the Cron page like any other.
        ServerCronJob::create([
            'server_id' => $this->server->id,
            'site_id' => $site->id,
            'cron_expression' => $cron,
            'command' => $command,
            'user' => $site->effectiveSystemUser($this->server),
            'enabled' => true,
            'description' => ucfirst($this->enable_framework).' scheduler — '.$site->name,
        ]);

        $this->reset(['enable_site_id', 'enable_framework']);
        $this->enable_cron_expression = '* * * * *';
        $this->toastSuccess(__('Scheduler enabled for :site.', ['site' => $site->name]));
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
