<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\GuardsBilledDeploys;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Deploy\Services\ServerlessDeployProgress;
use App\Modules\Serverless\Actions\DeleteServerlessFunction;
use App\Modules\Serverless\Jobs\ProvisionServerlessHostJob;
use App\Support\Serverless\ServerlessWorkspaceUrl;
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
 *
 * One of those derived states is "paused": RunSiteDeploymentJob drops a
 * human-triggered deploy on the floor when the owning org can't run billed
 * work, deliberately leaving no SiteDeployment row behind. Without deriving
 * that here the page has nothing to read and spins on "deploying" forever.
 */
#[Layout('layouts.app')]
class Journey extends Component
{
    use DispatchesToastNotifications;
    use GuardsBilledDeploys;

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

    /** Whether the delete-failed-run confirmation modal is open. */
    public bool $confirmingDeleteDeployment = false;

    /** Whether the delete-the-whole-function confirmation modal is open. */
    public bool $confirmingDeleteFunction = false;

    /** Type-to-confirm box for the destructive function delete. */
    public string $deleteFunctionConfirmName = '';

    /**
     * Rendered as a panel inside another page (the Deployments tab) rather
     * than as a standalone route — drops the breadcrumb and page padding.
     */
    public bool $embedded = false;

    /**
     * Request-scoped identity cache. Livewire rebuilds the component on every
     * poll, so these never persist across requests — they only stop the same
     * row being re-fetched by each method that needs it, and let the initial
     * page load reuse the models the controller already resolved for routing.
     */
    private ?Server $serverMemo = null;

    private ?Site $siteMemo = null;

    public function mount(Server $server, Site $site, bool $embedded = false): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->isDigitalOceanFunctionsHost(), 404);
        $this->authorize('view', $site);

        $this->serverId = $server->id;
        $this->siteId = $site->id;
        $this->embedded = $embedded;

        // The controller already loaded both to route and authorize — adopt
        // them instead of re-selecting the same two rows on first render.
        $this->serverMemo = $server;
        $this->siteMemo = $site;
    }

    private function server(): Server
    {
        return $this->serverMemo ??= Server::findOrFail($this->serverId);
    }

    private function site(): Site
    {
        return $this->siteMemo ??= Site::findOrFail($this->siteId);
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

        // RunSiteDeploymentJob silently no-ops a human-triggered deploy for a
        // pause-blocked org. Bail here instead, so the click gets an answer
        // rather than pinning sinceDeploymentId and polling for a row that is
        // never written.
        if ($this->blockedByDeployPause($site)) {
            return;
        }

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

    public function openDeleteDeploymentModal(): void
    {
        $this->confirmingDeleteDeployment = true;
    }

    public function closeDeleteDeploymentModal(): void
    {
        $this->confirmingDeleteDeployment = false;
    }

    /**
     * Discard the failed run this page is showing. Deploy history is a record,
     * not a lock — a function whose first deploy failed would otherwise show a
     * red journey forever, with no way to clear it short of deleting the
     * function. Only a finished, failed run can go: a running deploy is still
     * being written to by the worker (cancel it first), and a successful run is
     * the provenance of what is currently live.
     */
    public function deleteFailedDeployment(): void
    {
        $this->confirmingDeleteDeployment = false;

        $site = $this->site();
        $this->authorize('update', $site);

        $deployment = $this->latestDeployment();
        if ($deployment === null || $deployment->status !== SiteDeployment::STATUS_FAILED) {
            $this->toastError(__('There is no failed deploy to delete.'));

            return;
        }

        // The page bridges "triggered but not yet re-read" by pinning the id it
        // deployed from. Deleting that row must clear the pin, or every poll
        // keeps waiting on a deployment that no longer exists.
        if ($this->sinceDeploymentId === $deployment->id) {
            $this->sinceDeploymentId = null;
        }

        $deployment->delete();

        $this->toastSuccess(__('Failed run deleted.'));
    }

    public function openDeleteFunctionModal(): void
    {
        $this->authorize('delete', $this->site());
        $this->deleteFunctionConfirmName = '';
        $this->resetValidation();
        $this->confirmingDeleteFunction = true;
    }

    public function closeDeleteFunctionModal(): void
    {
        $this->confirmingDeleteFunction = false;
        $this->deleteFunctionConfirmName = '';
        $this->resetValidation();
    }

    /**
     * Delete the function outright, along with its DigitalOcean Functions
     * namespace.
     *
     * The escape hatch for a function that can't go forward: a namespace that
     * won't provision, or — the case this was written for — a deploy the org
     * isn't allowed to run. Both leave a function that exists, bills for its
     * namespace, and will never serve a request. Deleting the deploy row isn't
     * enough there, because the deploy never got as far as writing one.
     *
     * Deliberately NOT gated on the billing pause: this is the exit ramp, and
     * paywalling the way out is how orgs end up paying for infrastructure they
     * asked to be rid of.
     */
    public function deleteFunction(DeleteServerlessFunction $action): mixed
    {
        $site = $this->site();
        $this->authorize('delete', $site);

        if (trim($this->deleteFunctionConfirmName) !== $site->name) {
            $this->addError('deleteFunctionConfirmName', __('Type the function name exactly to confirm.'));

            return null;
        }

        $organization = $site->organization;
        $name = $site->name;

        // Audit before the delete — afterwards the subject is gone and the
        // entry would have nothing to point at.
        if ($organization !== null) {
            audit_log($organization, auth()->user(), 'serverless.function_deleted', $site, null, [
                'name' => $name,
                'server_id' => $site->server_id,
            ]);
        }

        $result = $action->handle($site);

        $this->confirmingDeleteFunction = false;

        // A namespace dply couldn't reach is the operator's problem to finish,
        // so name it rather than reporting a clean success.
        if ($result['remote_error'] !== null) {
            $this->toastError(__('Deleted :name from dply, but its DigitalOcean namespace could not be removed — delete it in the DigitalOcean console. (:error)', [
                'name' => $name,
                'error' => $result['remote_error'],
            ]));
        } else {
            $this->toastSuccess(__('Deleted :name.', ['name' => $name]));
        }

        return $this->redirect(route('serverless.index'), navigate: true);
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
        $siteFailed = $site->status === Site::STATUS_FUNCTIONS_FAILED;
        $deployStatus = $deployment?->status;
        $deployRunning = $deployStatus === SiteDeployment::STATUS_RUNNING;

        // Billing pause. Read off the org, not off a skipped deployment row —
        // the interactive path leaves no row at all, so a row-based check would
        // miss exactly the case that strands this page. Only meaningful before
        // the function is live: an already-live function stays live while the
        // org is paused, and only its redeploy button is gated.
        $deployPaused = ! $siteActive
            && ! $deployRunning
            && $this->deploysArePaused($site);
        $billingUrl = $deployPaused && $site->organization !== null
            ? route('billing.show', $site->organization)
            : null;

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
            if (($deployStatus === SiteDeployment::STATUS_FAILED || $siteFailed) && $state === 'active') {
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
            $deployPaused => 'blocked',
            $deployStatus === SiteDeployment::STATUS_FAILED || $siteFailed => 'failed',
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
                'detail' => match ($deployState) {
                    'failed' => __('The deploy failed — see the log below.'),
                    'blocked' => __('Deploys are paused for this organization — nothing was built.'),
                    default => __('Checking out the repo, building the artifact, pushing the action.'),
                },
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
        // A paused deploy is a resting state, not an in-flight one — polling it
        // would just re-render the same banner every 3s until the tab is closed.
        $shouldPoll = $bridging || (! $live && ! $failed && $deployState !== 'blocked');

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
            $failed => __('Deploy failed'),
            $deployState === 'blocked' => __('Deploys are paused'),
            $deployState === 'active' => __('Building & deploying…'),
            $namespaceState === 'active' => __('Provisioning namespace…'),
            default => __('Starting deploy…'),
        };

        // Page title — the panel is reused for both an in-flight deploy and
        // the resting "this is the last deploy" view.
        $title = match (true) {
            $live && ! $bridging => __('Latest deployment'),
            $deployState === 'blocked' => $site->name,
            default => __('Deploying :name', ['name' => $site->name]),
        };

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

        $failedStep = collect($deploySteps)->first(fn (array $step): bool => $step['state'] === 'failed');
        $errorSummary = $this->errorSummary(
            (string) ($deployment?->log_output ?? ''),
            is_array($failedStep) ? $failedStep : null,
        );

        $repoLabel = $this->repositoryLabel((string) ($site->git_repository_url ?? ''));

        return view('livewire.serverless.journey', [
            'workspaceUrl' => ServerlessWorkspaceUrl::show($site),
            // Where a just-shipped function actually needs attention next. Only
            // built once it's live — before that the page has one job.
            'nextSteps' => $live ? $this->nextSteps($site) : [],
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
            'stages' => $stages,
            'deploySteps' => $deploySteps,
            'live' => $live,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'deployPaused' => $deployState === 'blocked',
            'billingUrl' => $billingUrl,
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
            'errorSummary' => $errorSummary,
            'repoLabel' => $repoLabel,
            'failedStepLabel' => is_array($failedStep) ? (string) ($failedStep['label'] ?? '') : '',
        ]);
    }

    /**
     * The setup a freshly-deployed function usually still needs. Ordered by how
     * often it's the actual next thing: an app that just went live typically
     * needs its secrets before anything else.
     *
     * @return list<array{label: string, body: string, icon: string, href: string}>
     */
    private function nextSteps(Site $site): array
    {
        return [
            [
                'label' => __('Environment'),
                'body' => __('Add the env vars and secrets your app expects.'),
                'icon' => 'heroicon-o-key',
                'href' => route('serverless.environment', $site),
            ],
            [
                'label' => __('Domains & routing'),
                'body' => __('Put it behind your own domain instead of the raw URL.'),
                'icon' => 'heroicon-o-globe-alt',
                'href' => route('serverless.routing', $site),
            ],
            [
                'label' => __('Schedule & workers'),
                'body' => __('Run crons and background queue workers.'),
                'icon' => 'heroicon-o-clock',
                'href' => route('serverless.schedule', $site),
            ],
            [
                'label' => __('Logs & errors'),
                'body' => __('Watch requests land and catch failures early.'),
                'icon' => 'heroicon-o-document-text',
                'href' => route('serverless.logs', $site),
            ],
        ];
    }

    /**
     * Short owner/repo (or basename) for the header — full URL stays on hover.
     */
    private function repositoryLabel(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('~(?:github\.com|gitlab\.com|bitbucket\.org)[:/](.+?)(?:\.git)?$~i', $url, $matches) === 1) {
            return rtrim($matches[1], '/');
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && $path !== '/') {
            return ltrim($path, '/');
        }

        return $url;
    }

    /**
     * Pull a one-line reason for the failure banner (failed sub-step + last
     * meaningful log line). Keeps the full transcript in the log panel.
     *
     * @param  array{label?: string, detail?: string}|null  $failedStep
     */
    private function errorSummary(string $log, ?array $failedStep): string
    {
        $parts = [];
        if (is_array($failedStep) && ($failedStep['label'] ?? '') !== '') {
            $label = (string) $failedStep['label'];
            $detail = trim((string) ($failedStep['detail'] ?? ''));
            $parts[] = $detail !== '' ? $label.': '.$detail : $label;
        }

        $lines = preg_split('/\R/', $log) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) $lines[$i]);
            if ($line === '' || str_starts_with($line, '---') || str_starts_with($line, 'Detected ')) {
                continue;
            }
            // Prefer shell / composer style errors over long dumps.
            if (preg_match('/^(sh:|composer |error:|fatal:|Installing |\\[dply\\])/i', $line) === 1
                || str_contains(strtolower($line), 'not found')
                || str_contains(strtolower($line), 'failed')) {
                $parts[] = $line;
                break;
            }
            // Fallback: last non-empty line.
            $parts[] = $line;
            break;
        }

        $parts = array_values(array_unique(array_filter($parts)));

        return implode(' — ', array_slice($parts, 0, 2));
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
