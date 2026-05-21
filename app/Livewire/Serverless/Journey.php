<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Jobs\ProvisionServerlessHostJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\ServerlessDeployProgress;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The "watch it deploy" page for a serverless function. After
 * {@see Create} hands off to {@see CreateServerlessFunction}, the operator
 * lands here and watches the host namespace get provisioned, the function
 * artifact build, and the action go live — all without leaving the page.
 *
 * State is derived, not stored: each poll re-reads the host Server, the
 * function Site, and the latest SiteDeployment, then folds them into four
 * ordered stages. Nothing here writes deploy state — the jobs own that.
 */
#[Layout('layouts.app')]
class Journey extends Component
{
    use DispatchesToastNotifications;

    public string $serverId = '';

    public string $siteId = '';

    /**
     * The latest deployment id at the moment a deploy was triggered from
     * this page (retry or redeploy). While the newest row still matches it,
     * the worker hasn't created the fresh deployment yet — so the page keeps
     * polling to bridge that gap. Cleared once a newer row appears.
     */
    public ?string $sinceDeploymentId = null;

    /** Whether the cancel-deploy confirmation modal is open. */
    public bool $confirmingCancel = false;

    /**
     * Rendered as a panel inside another page (the Deployments tab) rather
     * than as a standalone route — drops the breadcrumb and page padding.
     */
    public bool $embedded = false;

    public function mount(Server $server, Site $site, bool $embedded = false): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->isDigitalOceanFunctionsHost(), 404);
        $this->authorize('view', $site);

        $this->serverId = $server->id;
        $this->siteId = $site->id;
        $this->embedded = $embedded;
    }

    private function server(): Server
    {
        return Server::findOrFail($this->serverId);
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    private function latestDeployment(): ?SiteDeployment
    {
        return SiteDeployment::query()
            ->where('site_id', $this->siteId)
            ->latest('created_at')
            ->first();
    }

    /**
     * Re-run namespace provisioning after it errored.
     */
    public function retryProvision(): void
    {
        $server = $this->server();
        $this->authorize('update', $server);

        if ($server->status !== Server::STATUS_ERROR) {
            return;
        }

        $server->update(['status' => Server::STATUS_PENDING]);
        ProvisionServerlessHostJob::dispatch($server->id);
        $this->toastSuccess(__('Retrying namespace provisioning…'));
    }

    /**
     * Re-run the function deploy after it failed.
     */
    public function retryDeploy(): void
    {
        $this->dispatchDeploy(__('Retrying the deploy…'));
    }

    /**
     * Redeploy a function that is already live — the same control, reused.
     */
    public function redeploy(): void
    {
        $this->dispatchDeploy(__('Redeploying…'));
    }

    /**
     * Queue a deploy and remember the deployment row we triggered from, so
     * the page bridges the gap until the worker creates the new one.
     */
    private function dispatchDeploy(string $toast): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->sinceDeploymentId = $this->latestDeployment()?->id ?? '';
        RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess($toast);
    }

    public function openCancelModal(): void
    {
        $this->confirmingCancel = true;
    }

    public function closeCancelModal(): void
    {
        $this->confirmingCancel = false;
    }

    /**
     * Request cancellation of the in-flight deploy. The deploy pipeline
     * checks for this at each step boundary and aborts cleanly.
     */
    public function cancelDeploy(ServerlessDeployProgress $progress): void
    {
        $this->confirmingCancel = false;

        $site = $this->site();
        $this->authorize('update', $site);

        $deployment = $this->latestDeployment();
        if ($deployment === null || $deployment->status !== SiteDeployment::STATUS_RUNNING) {
            $this->toastError(__('There is no deploy running to cancel.'));

            return;
        }

        $progress->requestCancel($site, $deployment->id);
        $this->toastSuccess(__('Cancelling the deploy — it will stop at the next step.'));
    }

    public function render(): View
    {
        $server = $this->server();
        $site = $this->site();
        $deployment = $this->latestDeployment();
        $config = $site->serverlessConfig();

        $meta = is_array($server->meta) ? $server->meta : [];
        $hostConfig = is_array($meta['digitalocean_functions'] ?? null) ? $meta['digitalocean_functions'] : [];
        $namespaceReady = ! empty($hostConfig['api_host'] ?? null);
        $serverErrored = $server->status === Server::STATUS_ERROR;

        $siteActive = $site->status === Site::STATUS_FUNCTIONS_ACTIVE;
        $deployStatus = $deployment?->status;
        $deployRunning = $deployStatus === SiteDeployment::STATUS_RUNNING;

        // "Live" means the function is up AND nothing is mid-deploy — so a
        // redeploy of an already-live function correctly reads as in-flight
        // rather than instantly "done".
        $live = $siteActive && ! $deployRunning && $deployStatus !== SiteDeployment::STATUS_FAILED;

        // Fine-grained sub-steps the deploy pipeline records as it runs —
        // checkout, dependencies, adapter, package, upload. A failed deploy
        // leaves its in-flight step stuck 'active'; surface that as failed.
        $deploySteps = [];
        foreach ($deployment?->phaseSteps(ServerlessDeployProgress::PHASE) ?? [] as $step) {
            $state = (string) ($step['state'] ?? 'pending');
            if ($deployStatus === SiteDeployment::STATUS_FAILED && $state === 'active') {
                $state = 'failed';
            }
            $deploySteps[] = [
                'label' => (string) ($step['label'] ?? ''),
                'detail' => (string) ($step['detail'] ?? ''),
                'state' => $state,
                'duration' => $this->formatDuration(is_int($step['duration_ms'] ?? null) ? $step['duration_ms'] : null),
            ];
        }

        // Stage 2 — provisioning the DO Functions namespace.
        $namespaceState = match (true) {
            $namespaceReady => 'done',
            $serverErrored => 'failed',
            default => 'active',
        };

        // Stage 3 — checkout, artifact build, action deploy.
        $deployState = match (true) {
            $namespaceState !== 'done' => 'pending',
            $deployRunning => 'active',
            $deployStatus === SiteDeployment::STATUS_FAILED => 'failed',
            $deployStatus === SiteDeployment::STATUS_SUCCESS || $siteActive => 'done',
            default => 'active',
        };

        // Stage 4 — the function answering requests.
        $liveState = match (true) {
            $live => 'done',
            $deployState === 'done' => 'active',
            $deployState === 'failed' => 'pending',
            default => 'pending',
        };

        $stages = [
            [
                'key' => 'created',
                'label' => __('Function created'),
                'detail' => __('Repository and runtime recorded.'),
                'state' => 'done',
            ],
            [
                'key' => 'namespace',
                'label' => __('Provisioning namespace'),
                'detail' => $namespaceState === 'failed'
                    ? __('Could not create the DigitalOcean Functions namespace.')
                    : __('Creating the DigitalOcean Functions namespace.'),
                'state' => $namespaceState,
            ],
            [
                'key' => 'deploy',
                'label' => __('Building & deploying'),
                'detail' => $deployState === 'failed'
                    ? __('The deploy failed — see the log below.')
                    : __('Checking out the repo, building the artifact, pushing the action.'),
                'state' => $deployState,
            ],
            [
                'key' => 'live',
                'label' => __('Live'),
                'detail' => __('The function is answering requests.'),
                'state' => $liveState,
            ],
        ];

        // Bridge the gap after a deploy is triggered here: keep polling
        // until a newer deployment row replaces the one we triggered from.
        $bridging = $this->sinceDeploymentId !== null
            && ($deployment?->id ?? '') === $this->sinceDeploymentId;
        if (! $bridging) {
            $this->sinceDeploymentId = null;
        }

        $failed = $namespaceState === 'failed' || $deployState === 'failed';
        $shouldPoll = $bridging || (! $live && ! $failed);

        // A deploy can be cancelled while its step pipeline is running.
        $cancellable = $deployState === 'active' && $deployStatus === SiteDeployment::STATUS_RUNNING;
        $cancelled = $deployStatus === SiteDeployment::STATUS_FAILED
            && str_contains(strtolower((string) ($deployment?->log_output ?? '')), 'cancelled by operator');

        $actionUrl = is_string($config['action_url'] ?? null) ? $config['action_url'] : null;

        // Elapsed — anchored on the current deploy's start (falling back to
        // the site's creation before any deploy exists), frozen at finish.
        $anchor = $deployment?->started_at ?? $site->created_at;
        $endpoint = ($deployment?->finished_at && ! $deployRunning) ? $deployment->finished_at : now();
        $elapsedSeconds = $anchor ? max(0, (int) $anchor->diffInSeconds($endpoint)) : 0;
        $elapsedLabel = $live ? __('Deployed in') : __('Elapsed');

        // Weighted progress across the four stages; the deploy stage scales
        // by how many of its sub-steps have completed.
        $weights = ['created' => 15, 'namespace' => 25, 'deploy' => 45, 'live' => 15];
        $percent = 0;
        foreach ($stages as $st) {
            $weight = $weights[$st['key']] ?? 0;
            if ($st['state'] === 'done') {
                $percent += $weight;
            } elseif ($st['key'] === 'deploy' && $st['state'] === 'active' && $deploySteps !== []) {
                $subDone = count(array_filter($deploySteps, fn ($s) => $s['state'] === 'done'));
                $percent += (int) round($weight * $subDone / count($deploySteps));
            }
        }
        $percent = max(0, min(100, $percent));

        $headline = match (true) {
            $bridging => __('Starting deploy…'),
            $live => __('Function is live'),
            $cancelled => __('Deploy cancelled'),
            $failed => __('Deploy stopped'),
            $deployState === 'active' => __('Building & deploying…'),
            $namespaceState === 'active' => __('Provisioning namespace…'),
            default => __('Starting deploy…'),
        };

        // Page title — the panel is reused for both an in-flight deploy and
        // the resting "this is the last deploy" view.
        $title = ($live && ! $bridging)
            ? __('Latest deployment')
            : __('Deploying :name', ['name' => $site->name]);

        // Function facts — populated progressively as the deploy resolves them.
        $facts = [
            ['label' => __('Region'), 'value' => $server->region ?: null],
            ['label' => __('Runtime'), 'value' => $this->stringOrNull($config['runtime'] ?? null)],
            ['label' => __('Namespace'), 'value' => $this->stringOrNull($hostConfig['namespace'] ?? null), 'mono' => true],
            ['label' => __('Package'), 'value' => $this->stringOrNull($config['package'] ?? null), 'mono' => true],
            ['label' => __('Action name'), 'value' => $this->stringOrNull($config['action_name'] ?? null), 'mono' => true],
            ['label' => __('Entry function'), 'value' => $this->stringOrNull($config['entrypoint'] ?? null), 'mono' => true],
            ['label' => __('Revision'), 'value' => $this->stringOrNull($config['last_revision_id'] ?? null), 'mono' => true],
        ];

        $deployDurationMs = ($deployment?->started_at && $deployment->finished_at)
            ? max(0, (int) round($deployment->started_at->diffInMilliseconds($deployment->finished_at)))
            : null;

        return view('livewire.serverless.journey', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
            'stages' => $stages,
            'deploySteps' => $deploySteps,
            'live' => $live,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'cancellable' => $cancellable,
            'shouldPoll' => $shouldPoll,
            'namespaceState' => $namespaceState,
            'deployState' => $deployState,
            'actionUrl' => $actionUrl,
            'log' => $deployment?->log_output ?? '',
            'headline' => $headline,
            'title' => $title,
            'percent' => $percent,
            'elapsedHuman' => $this->humanizeSeconds($elapsedSeconds),
            'elapsedLabel' => $elapsedLabel,
            'facts' => $facts,
            'deployDuration' => $this->formatDuration($deployDurationMs),
            'deployStartedAt' => $deployment?->started_at,
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            $rest = $seconds % 60;

            return $rest > 0 ? "{$minutes}m {$rest}s" : "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);

        return $hours.'h '.($minutes % 60).'m';
    }

    private function formatDuration(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }

        if ($ms < 1000) {
            return $ms.'ms';
        }

        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.').'s';
        }

        return $this->humanizeSeconds((int) round($seconds));
    }
}
