<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\CreateSiteDatabaseJob;
use App\Jobs\PushSiteEnvJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Remediations\DatabaseConnectionDiagnosis;
use App\Services\Remediations\DatabaseConnectionDiagnostic;
use App\Services\Servers\DatabaseEngineReadinessGuard;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Inline "fix a database-connection deploy failure" panel, rendered under the
 * failed step in the deploy timeline when the failure matches the
 * `database_connection_failed` remediation and this is the site's latest,
 * still-failed deployment.
 *
 * It runs a Level-A (no-SSH) diagnosis ({@see DatabaseConnectionDiagnostic}),
 * shows the recommended fix, and hosts two guided modal flows that reuse the
 * existing machinery rather than re-implementing it:
 *   - Attach a database → {@see CreateSiteDatabaseJob} (write + push env forced
 *     on, per Q11, so the retry actually has a working connection).
 *   - Inject DB_* → merges into the env cache and {@see PushSiteEnvJob}.
 * Once the env push has landed, "Apply & retry" resumes the failed deploy from
 * its release phase (reusing the staged release) instead of a full redeploy.
 *
 * The read-only diagnosis is visible to anyone who can view the deploy; the fix
 * actions are gated to the site's update policy (Q9).
 */
class DeployDatabaseFix extends Component
{
    use AuthorizesRequests;
    use DispatchesToastNotifications;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    public SiteDeployment $deployment;

    /** Attach-a-database form. */
    public string $new_db_name = '';

    public string $new_db_engine = '';

    /** Engines installed on the box (resolved lazily when the attach modal opens — that read may SSH). */
    public bool $enginesLoaded = false;

    /** @var list<string> */
    public array $installedEngines = [];

    /** Inject-DB_* form. */
    public string $inject_env = '';

    /** The console run for the fix we last dispatched, so we can gate "retry" on its env push completing. */
    public ?string $fixRunId = null;

    public function mount(Server $server, Site $site, SiteDeployment $deployment): void
    {
        // Diagnosis is read-only — anyone who can view the site can see it.
        $this->authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->deployment = $deployment;

        $base = Str::slug((string) $site->name, '_') ?: 'app';
        $this->new_db_name = substr($base, 0, 64);
    }

    /** The Level-A diagnosis driving this panel. */
    #[Computed]
    public function diagnosis(): DatabaseConnectionDiagnosis
    {
        return app(DatabaseConnectionDiagnostic::class)->for($this->site, $this->failureText());
    }

    /** Whether the current viewer may run the fixes (the diagnosis itself is always visible). */
    #[Computed]
    public function canFix(): bool
    {
        return Gate::allows('update', $this->site);
    }

    /**
     * "Apply & retry" is only safe once the env actually landed on the box — a
     * completed env_push recorded after this deployment failed. Both the attach
     * flow (CreateSiteDatabaseJob chains PushSiteEnvJob) and the inject flow
     * produce one, so this is the single source of truth (Q5).
     */
    #[Computed]
    public function retryReady(): bool
    {
        $since = $this->deployment->finished_at ?? $this->deployment->created_at;

        return ConsoleAction::query()
            ->forSubject($this->site)
            ->where('kind', 'env_push')
            ->where('status', ConsoleAction::STATUS_COMPLETED)
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }

    /**
     * Keep polling after a fix is dispatched until the env has actually landed
     * (retryReady) — the attach flow's watched run (site_db_create) finishes
     * BEFORE its chained env_push, so we can't stop when the watched run alone
     * completes. Bounded by "is anything still in flight" so a failed push stops
     * the poll instead of spinning forever.
     */
    #[Computed]
    public function shouldPoll(): bool
    {
        if ($this->fixRunId === null || $this->retryReady) {
            return false;
        }

        $since = $this->deployment->finished_at ?? $this->deployment->created_at;

        return ConsoleAction::query()
            ->forSubject($this->site)
            ->whereIn('kind', ['site_db_create', 'env_push'])
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }

    public function openAttachModal(): void
    {
        $this->authorize('update', $this->site);

        $this->loadInstalledEngines();
        if ($this->installedEngines !== [] && ! in_array($this->new_db_engine, $this->installedEngines, true)) {
            // Prefer the engine the app already expects, else the first installed one.
            $preferred = $this->diagnosis()->engineFamily;
            $this->new_db_engine = ($preferred !== null && in_array($preferred, $this->installedEngines, true))
                ? $preferred
                : $this->installedEngines[0];
        }

        $this->resetErrorBag(['new_db_name', 'new_db_engine']);
        $this->dispatch('open-modal', 'deploy-db-fix-attach');
    }

    /**
     * Provision a database for the site and wire the connection into the env.
     * write + push are FORCED on here (Q11): the whole point is for the retry to
     * find a working connection, so a half-applied "created but not pushed" fix
     * can't lead to an identical second failure.
     */
    public function createDatabase(): void
    {
        $this->authorize('update', $this->site);

        $readiness = app(DatabaseEngineReadinessGuard::class)->check($this->server, $this->new_db_engine);
        if (! ($readiness['ok'] ?? false)) {
            $this->addError('new_db_engine', (string) ($readiness['reason'] ?? __('That engine isn’t ready on this server.')));

            return;
        }

        $this->validate([
            'new_db_name' => [
                'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('server_databases', 'name')->where('server_id', $this->server->id),
            ],
            // Connection-refused fixes only make sense for the TCP engines.
            'new_db_engine' => 'required|in:mysql,mariadb,postgres',
        ], [
            'new_db_name.unique' => __('A database named :name is already tracked on this server.', ['name' => $this->new_db_name]),
        ], [
            'new_db_name' => __('Name'),
            'new_db_engine' => __('Engine'),
        ]);

        $username = Str::limit(Str::slug($this->new_db_name, '_') ?: 'db', 28, '').'_'.Str::lower(Str::random(4));
        $password = ServerDatabase::generateConnectionSafePassword();

        $db = ServerDatabase::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $this->site->id,
            'name' => $this->new_db_name,
            'engine' => $this->new_db_engine,
            'username' => $username,
            'password' => $password,
            'host' => '127.0.0.1',
        ]);

        $run = $this->seedConsoleRun('site_db_create', __('Create :engine database :name', [
            'engine' => DatabaseWorkspaceEngines::label($db->engine),
            'name' => $db->name,
        ]));

        CreateSiteDatabaseJob::dispatch(
            $db->id,
            $this->site->id,
            true,  // writeEnv — forced
            true,  // pushEnv — forced (Q11)
            (string) (auth()->id() ?? '') ?: null,
            (string) $run->id,
        );

        $this->fixRunId = (string) $run->id;
        $this->watchConsoleAction(
            $run,
            __('Database :name created and pushed to the server. Retry the deploy below.', ['name' => $db->name]),
            __('Could not attach the database.'),
        );
        $this->dispatch('close-modal', 'deploy-db-fix-attach');
        $this->dispatch('dply-console-action-focus');
        $this->toastConsoleActionQueued();
    }

    /**
     * Provision/sync the already-attached database resource onto the server: with
     * the now-idempotent + fail-loud provisioner, this (re)creates the role/db and
     * sets the stored password, then pushes the env. This is the fix when dply
     * tracks a resource that was never actually created on the box (or drifted) —
     * the desync the silent-provisioning bug used to hide.
     */
    public function repairResource(): void
    {
        $this->authorize('update', $this->site);

        $resourceId = $this->diagnosis()->resourceId;
        $db = $resourceId !== null
            ? ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($resourceId)->first()
            : null;

        if ($db === null) {
            $this->toastError(__('There’s no attached database to repair.'));

            return;
        }

        $run = $this->seedConsoleRun('site_db_create', __('Repair :name on the server', ['name' => $db->name]));

        CreateSiteDatabaseJob::dispatch(
            $db->id,
            $this->site->id,
            true,  // writeEnv
            true,  // pushEnv (Q11)
            (string) (auth()->id() ?? '') ?: null,
            (string) $run->id,
        );

        $this->fixRunId = (string) $run->id;
        $this->watchConsoleAction(
            $run,
            __('Provisioned :name on the server and pushed the connection. Retry the deploy below.', ['name' => $db->name]),
            __('Could not provision the database on the server.'),
        );
        $this->dispatch('dply-console-action-focus');
        $this->toastConsoleActionQueued();
    }

    public function openInjectModal(): void
    {
        $this->authorize('update', $this->site);

        $this->inject_env = $this->currentDbEnvBlock();
        $this->resetErrorBag(['inject_env']);
        $this->dispatch('open-modal', 'deploy-db-fix-inject');
    }

    /**
     * Merge the operator-supplied DB_* into the site's env cache and push it
     * live. The push is mandatory (Q11) — the retry reads the env on the box.
     */
    public function saveInject(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);

        $this->validate(['inject_env' => 'required|string|max:8000']);

        $incoming = $parser->parse($this->inject_env);
        if (($incoming['variables'] ?? []) === []) {
            $this->addError('inject_env', __('No KEY=value lines were found.'));

            return;
        }

        if (! $this->site->server?->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host doesn’t support pushing a .env over SSH.'));

            return;
        }

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $this->site->forceFill([
            'env_file_content' => $writer->render(
                array_merge($existing['variables'], $incoming['variables']),
                array_merge($existing['comments'], $incoming['comments']),
            ),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $run = $this->seedConsoleRun('env_push', __('Push environment to :site', ['site' => $this->site->name]));

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? '') ?: null,
            (string) $run->id,
        );

        $this->fixRunId = (string) $run->id;
        $this->watchConsoleAction(
            $run,
            __('Environment pushed to the server. Retry the deploy below.'),
            __('Could not push the environment.'),
        );
        $this->dispatch('close-modal', 'deploy-db-fix-inject');
        $this->dispatch('dply-console-action-focus');
        $this->toastConsoleActionQueued();
    }

    /**
     * Re-run the failed deploy. Resumes from the release phase (reusing the
     * staged release) when the deployment is resumable, else a full redeploy.
     * Gated in the view on {@see retryReady} so the env has actually landed.
     */
    public function retryDeploy()
    {
        $this->authorize('update', $this->site);

        $deployment = $this->deployment->fresh();
        if ($deployment !== null && $deployment->isResumable()) {
            // Optimistic marker so the deploy panel reads "Deploying…" at once.
            Cache::put('site-deploy-active:'.$this->site->id, [
                'started_at' => now()->toIso8601String(),
                'deployment_id' => null,
            ], 600);

            \App\Jobs\RunSiteDeploymentJob::dispatch(
                $this->site,
                SiteDeployment::TRIGGER_RESUME,
                null,
                (string) (auth()->id() ?? '') ?: null,
                $deployment->id,
            );

            $this->toastSuccess(__('Resuming the deploy from the :phase phase.', ['phase' => $deployment->resumeStartPhase()]));
        } else {
            \App\Jobs\RunSiteDeploymentJob::dispatch($this->site->fresh(), SiteDeployment::TRIGGER_MANUAL);
            $this->toastSuccess(__('Re-deploying from scratch.'));
        }

        return $this->redirect(
            route('sites.deployments.index', ['server' => $this->server, 'site' => $this->site]),
            navigate: true,
        );
    }

    /** Resolve installed TCP database engines on the box (cached SSH probe — user-initiated, not on render). */
    private function loadInstalledEngines(): void
    {
        $caps = app(ServerDatabaseHostCapabilities::class)->forServer($this->server);
        $this->installedEngines = array_values(array_filter(
            ['mysql', 'mariadb', 'postgres'],
            fn (string $engine): bool => (bool) ($caps[$engine] ?? false),
        ));
        $this->enginesLoaded = true;
    }

    /** The current DB_* lines from the site's env, or a starter template for the resolved engine family. */
    private function currentDbEnvBlock(): string
    {
        $parser = app(DotEnvFileParser::class);
        $vars = $parser->parse((string) ($this->site->env_file_content ?? ''))['variables'] ?? [];

        $lines = [];
        foreach ($vars as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'DB_')) {
                $lines[] = $key.'='.$value;
            }
        }

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        $family = $this->diagnosis()->engineFamily ?? 'mysql';
        $port = $family === 'postgres' ? '5432' : '3306';
        $connection = $family === 'postgres' ? 'pgsql' : 'mysql';

        return implode("\n", [
            'DB_CONNECTION='.$connection,
            'DB_HOST=127.0.0.1',
            'DB_PORT='.$port,
            'DB_DATABASE=',
            'DB_USERNAME=',
            'DB_PASSWORD=',
        ]);
    }

    /** Full failure output to diagnose — the overall log plus any recorded step outputs. */
    private function failureText(): string
    {
        $parts = [(string) $this->deployment->log_output];

        $phaseResults = is_array($this->deployment->phase_results ?? null) ? $this->deployment->phase_results : [];
        array_walk_recursive($phaseResults, function ($value) use (&$parts): void {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }

    /**
     * Seed a queued ConsoleAction on the site so the watcher has a row before the
     * worker picks the job up. Mirrors {@see Database::seedConsoleRun()}.
     */
    private function seedConsoleRun(string $kind, ?string $label = null): ConsoleAction
    {
        ConsoleAction::query()
            ->forSubject($this->site)
            ->notDismissed()
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => auth()->id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    public function render(): View
    {
        // Keep polling while a dispatched fix is resolving so retryReady flips on.
        if ($this->watchedConsoleRunId !== null) {
            $this->resolveWatchedConsoleAction();
        }

        return view('livewire.sites.deploy-database-fix');
    }
}
