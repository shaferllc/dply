<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\PreflightSiteSetupJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\SiteBindingManager;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Post-repo-connect SETUP WIZARD for import/preset VM sites — the guided
 * "now configure your site" flow that runs after {@see ChooseApp} connects a
 * repository (see /grill design). Rendered inside the site shell at
 * `sites.setup`; the site stays LIVE (splash serving) throughout, so the
 * sidebar is always one click away and "I'll configure later" is a first-class
 * escape that preserves the held state.
 *
 * Three scan-derived steps:
 *   1. Environment  — required plain vars, pre-filled from the pre-flight
 *                     scan's .env.example. Blocks advancing until satisfied.
 *   2. Resources    — databases / cache / queue / mail the env implies, via the
 *                     existing {@see ManagesSiteBindings} machinery (provision a
 *                     ServerDatabase, inject DB_*, etc.). Advisory / skippable.
 *   3. Review       — auto-detected build, plus the completeness gate: Deploy
 *                     unlocks only when every required key is satisfied.
 *
 * Lifecycle is driven by meta.setup.state (written by
 * {@see PreflightSiteSetupJob}): 'scanning' shows an analyzing state; a clean
 * scan flips to 'deploying' and we bounce to the live site; 'needs_setup' /
 * 'scan_failed' land in the steps.
 */
#[Layout('layouts.app')]
class SiteSetup extends Component
{
    use DispatchesToastNotifications;
    use ManagesSiteBindings;

    public Server $server;

    public Site $site;

    /** Resources step — inline "create a database" form. */
    public string $dbName = '';

    public string $dbEngine = '';

    /** Active step: 'environment' | 'resources' | 'review'. */
    #[Url(as: 'step', except: '')]
    public string $step = '';

    /**
     * Editable env values for the Environment step (key => value), parsed from
     * the encrypted cache the pre-flight seeded from .env.example.
     *
     * @var array<string, string>
     */
    public array $env = [];

    /** Key prefixes that denote a provisionable/configurable resource (step 2). */
    private const RESOURCE_PREFIXES = ['DB_', 'DATABASE_', 'REDIS_', 'QUEUE_', 'CACHE_', 'MAIL_', 'MAILER_'];

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->isVmHost(), 404);

        Gate::authorize('view', $site);
        Gate::authorize('update', $server);

        $this->server = $server;
        $this->site = $site;

        // Only sites genuinely in the first-deploy setup flow belong here. A
        // clean scan that already kicked the deploy ('deploying'), an
        // already-deployed site, or one that never connected a repo → just land
        // on the live site (never dead-end on a 404).
        if (! $site->isInFirstDeploySetup()) {
            $this->redirectRoute('sites.show', ['server' => $server->id, 'site' => $site->id], navigate: true);

            return;
        }

        $this->loadEnvFromCache();
        $this->dbName = Str::slug((string) $this->site->slug, '_') ?: 'app';
        $engines = $this->installedDbEngines();
        $this->dbEngine = $engines[0]['value'] ?? 'mysql';

        if ($this->step === '' || ! in_array($this->step, ['environment', 'resources', 'review'], true)) {
            $this->step = $this->firstIncompleteStep();
        }
    }

    /**
     * Provisionable database engines installed on this server (the Resources
     * step's engine choices), architecture-gated to what the binding manager
     * can actually create.
     *
     * @return list<array{value: string, label: string}>
     */
    public function installedDbEngines(): array
    {
        $supported = ['mysql', 'postgres', 'sqlite'];

        return $this->server->databaseEngines()
            ->get(['engine'])
            ->map(fn ($e) => DatabaseWorkspaceEngines::family((string) $e->engine))
            ->filter(fn (string $family) => in_array($family, $supported, true))
            ->unique()
            ->values()
            ->map(fn (string $family) => ['value' => $family, 'label' => DatabaseWorkspaceEngines::label($family)])
            ->all();
    }

    /**
     * Create a fresh database on the server and inject its DB_* credentials
     * into the env cache — the Resources step's primary action. Reuses the
     * binding machinery ({@see SiteBindingManager::provisionNew}) which adopts
     * the connection vars into env_file_content.
     */
    public function createDatabase(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        $name = trim($this->dbName);
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            $this->addError('dbName', __('Database name must be alphanumeric / underscore.'));

            return;
        }

        try {
            $manager->provisionNew($this->site, 'database', [
                'name' => $name,
                'engine' => $this->dbEngine !== '' ? $this->dbEngine : 'mysql',
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->loadEnvFromCache();
        $this->resetErrorBag('dbName');
        $this->toastSuccess(__('Database created and connected.'));
    }

    /**
     * Poll target for the "analyzing" state: re-resolve where the site should
     * be once the pre-flight job finishes. Clean scan → live site; held →
     * settle into the steps; failed → the fix-it step.
     */
    public function pollPreflight(): void
    {
        $this->site->refresh();

        if (! $this->site->isInFirstDeploySetup()) {
            $this->redirectRoute('sites.show', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);

            return;
        }

        if (! $this->site->isPreflightScanning()) {
            $this->loadEnvFromCache();
            $this->step = $this->firstIncompleteStep();
        }
    }

    /** Re-run the pre-flight scan after a scan failure (bad URL / access fixed). */
    public function rescan(): void
    {
        Gate::authorize('update', $this->server);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['setup'] = ['state' => 'scanning', 'started_at' => now()->toISOString()];
        $this->site->forceFill(['meta' => $meta])->save();
        $this->site->refresh();

        PreflightSiteSetupJob::dispatch($this->site->id, (string) auth()->id());
    }

    /** Escape hatch — leave setup for the live site; the held state persists. */
    public function configureLater(): mixed
    {
        return $this->redirectRoute('sites.show', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);
    }

    public function goToStep(string $step): void
    {
        if (in_array($step, ['environment', 'resources', 'review'], true)) {
            $this->step = $step;
        }
    }

    /** Persist the Environment step into the encrypted cache and advance. */
    public function saveEnvironment(): void
    {
        Gate::authorize('update', $this->site);

        // Block advancing while any required plain var is still empty.
        $missing = [];
        foreach ($this->planEnvFields() as $field) {
            if ($field['required'] && trim((string) ($this->env[$field['key']] ?? '')) === '') {
                $missing[] = $field['key'];
            }
        }
        if ($missing !== []) {
            $this->addError('env', __('Fill the required variables to continue: :keys', ['keys' => implode(', ', $missing)]));

            return;
        }

        $this->writeEnvCache($this->env);
        $this->resetErrorBag('env');
        $this->step = 'resources';
    }

    /** Persist any resource-key edits from the Resources step and advance to Review. */
    public function saveResourcesAndReview(): void
    {
        Gate::authorize('update', $this->site);

        $this->writeEnvCache($this->env);
        $this->step = 'review';
    }

    /** Final action: dispatch the first deploy (which composes .env from the cache). */
    public function finishAndDeploy(SiteDeployPipelineManager $pipeline, SiteDeploySyncCoordinator $coordinator): mixed
    {
        Gate::authorize('update', $this->server);

        // Persist any last edits, then enforce the completeness gate.
        $this->writeEnvCache($this->env);
        $this->site->refresh();

        if ($this->site->unsatisfiedRequiredEnvKeys() !== []) {
            $this->addError('deploy', __('Some required variables are still unset — finish them before deploying.'));
            $this->step = 'review';

            return null;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['setup'] = ['state' => 'deploying', 'deployed_at' => now()->toISOString()];
        $this->site->forceFill(['meta' => $meta])->save();

        $fresh = $this->site->fresh() ?? $this->site;
        $pipeline->seedRuntimeDefaults($fresh, (string) $fresh->runtime ?: 'php');
        $coordinator->dispatchManualForGroup($fresh->fresh() ?? $fresh);

        return $this->redirectRoute('sites.show', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);
    }

    // --- View data -----------------------------------------------------

    /**
     * Required + optional NON-resource env fields for the Environment step,
     * pre-filled from the cache. Required (blocking) ones sort first.
     *
     * @return list<array{key: string, value: string, example: ?string, required: bool, sources: list<string>}>
     */
    public function planEnvFields(): array
    {
        $fields = [];
        foreach ($this->requirementKeys() as $key) {
            $name = (string) ($key['key'] ?? '');
            if ($name === '' || $name === 'APP_KEY' || $this->isResourceKey($name)) {
                continue;
            }
            $fields[] = [
                'key' => $name,
                'value' => (string) ($this->env[$name] ?? $key['example'] ?? ''),
                'example' => $key['example'] ?? null,
                'required' => (bool) ($key['required'] ?? false),
                'sources' => is_array($key['sources'] ?? null) ? $key['sources'] : [],
            ];
        }

        usort($fields, static fn (array $a, array $b): int => [$b['required'], $a['key']] <=> [$a['required'], $b['key']]);

        return $fields;
    }

    /**
     * Resource-shaped keys grouped by family (database/cache/queue/mail) for
     * the Resources step. Each group reports whether it's still unsatisfied.
     *
     * @return list<array{family: string, label: string, keys: list<string>, satisfied: bool}>
     */
    public function resourceGroups(): array
    {
        $families = [
            'database' => ['label' => __('Database'), 'prefixes' => ['DB_', 'DATABASE_'], 'keys' => []],
            'cache' => ['label' => __('Cache / Redis'), 'prefixes' => ['REDIS_', 'CACHE_'], 'keys' => []],
            'queue' => ['label' => __('Queue'), 'prefixes' => ['QUEUE_'], 'keys' => []],
            'mail' => ['label' => __('Mail'), 'prefixes' => ['MAIL_', 'MAILER_'], 'keys' => []],
        ];

        foreach ($this->requirementKeys() as $key) {
            $name = (string) ($key['key'] ?? '');
            if ($name === '' || ! $this->isResourceKey($name)) {
                continue;
            }
            foreach ($families as $family => &$cfg) {
                foreach ($cfg['prefixes'] as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $cfg['keys'][] = $name;
                        break 2;
                    }
                }
            }
            unset($cfg);
        }

        $missing = $this->site->unsatisfiedRequiredEnvKeys();

        $groups = [];
        foreach ($families as $family => $cfg) {
            if ($cfg['keys'] === []) {
                continue;
            }
            $groups[] = [
                'family' => $family,
                'label' => $cfg['label'],
                'keys' => array_values(array_unique($cfg['keys'])),
                'satisfied' => array_intersect($cfg['keys'], $missing) === [],
            ];
        }

        return $groups;
    }

    /** Required keys still unsatisfied — the "N left" count and Deploy gate. */
    public function missingRequired(): array
    {
        return $this->site->unsatisfiedRequiredEnvKeys();
    }

    public function render(): View
    {
        return view('livewire.sites.site-setup');
    }

    // --- Internals -----------------------------------------------------

    private function loadEnvFromCache(): void
    {
        $this->env = [];
        if (filled($this->site->env_file_content)) {
            $parsed = app(DotEnvFileParser::class)->parse((string) $this->site->env_file_content);
            $this->env = is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];
        }
    }

    /**
     * Merge $values into the existing cache and persist. Keeps keys the wizard
     * doesn't surface (e.g. resource keys adopted by a binding).
     *
     * @param  array<string, string>  $values
     */
    private function writeEnvCache(array $values): void
    {
        $parser = app(DotEnvFileParser::class);
        $writer = app(DotEnvFileWriter::class);

        $current = [];
        if (filled($this->site->env_file_content)) {
            $parsed = $parser->parse((string) $this->site->env_file_content);
            $current = is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];
        }

        foreach ($values as $key => $value) {
            $current[$key] = (string) $value;
        }

        $this->site->forceFill([
            'env_file_content' => $writer->render($current),
        ])->save();
        $this->site->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementKeys(): array
    {
        $keys = data_get($this->site->envRequirements(), 'keys');

        return is_array($keys) ? $keys : [];
    }

    private function isResourceKey(string $name): bool
    {
        foreach (self::RESOURCE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function firstIncompleteStep(): string
    {
        // Step 1 incomplete while a required plain var is empty.
        foreach ($this->planEnvFields() as $field) {
            if ($field['required'] && trim((string) ($this->env[$field['key']] ?? '')) === '') {
                return 'environment';
            }
        }

        // Step 2 incomplete while a required resource key is unmet.
        foreach ($this->resourceGroups() as $group) {
            if (! $group['satisfied']) {
                return 'resources';
            }
        }

        return 'review';
    }
}
