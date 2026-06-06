<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelinePalette;
use App\Support\Sites\DeployScriptComposer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The simple TEXT deploy pipeline: three editable shell scripts (Build /
 * Release / Restart), built-in framework presets, a searchable runtime-aware
 * command catalog, a zero-downtime (atomic) toggle, and shell deploy hooks.
 *
 * It edits ONLY the freeform TYPE_CUSTOM portion of each phase — typed steps and
 * hooks authored in the visual builder are preserved and shown read-only (see
 * {@see DeployScriptComposer}). The simple editor is the live default while the
 * visual step builder is gated behind FEATURE_WORKSPACE_DEPLOY_PIPELINE_VISUAL;
 * in production it is the only deploy editor, so it carries hooks + atomic on its
 * own rather than deferring to the builder.
 */
#[Layout('layouts.app')]
class DeployScript extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithUnsavedChangesBar;

    public Server $server;

    public Site $site;

    /** Render inside the deploy hub tab without the page chrome. */
    public bool $embedded = false;

    public string $build = '';

    public string $release = '';

    public string $restart = '';

    /**
     * Mirrors {@see Site::$deploy_strategy} `atomic` for the zero-downtime toggle.
     * Deliberately NOT named `zero_downtime_enabled` — the host WorkspacePipeline
     * tracks a property by that name and its document-level dirty tracker would
     * otherwise flip on this embedded toggle too.
     */
    public bool $atomic_release = false;

    /**
     * When true the user opts out of dply's managed restart (the FPM reload /
     * service restart). User-authored restart steps still run; the operator is
     * responsible for picking up the new release themselves.
     */
    public bool $managed_restart_enabled = true;

    // --- Shell deploy hook form (lean; shell-only, positional anchors) ---

    public bool $hook_form_open = false;

    public ?string $editing_hook_id = null;

    public string $hook_anchor = SiteDeployHook::ANCHOR_AFTER_ACTIVATE;

    public string $hook_label = '';

    public string $hook_script = '';

    public int $hook_timeout = 900;

    /** Positional anchors the simple editor exposes (no after_step — that needs a step picker). */
    public const HOOK_ANCHORS = [
        SiteDeployHook::ANCHOR_BEFORE_CLONE,
        SiteDeployHook::ANCHOR_AFTER_CLONE,
        SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
        SiteDeployHook::ANCHOR_AFTER_ACTIVATE,
    ];

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->loadFromSite();
    }

    private function loadFromSite(): void
    {
        $this->site->refresh();
        $rendered = app(DeployScriptComposer::class)->render($this->site);
        $this->build = $rendered['build'] ?? '';
        $this->release = $rendered['release'] ?? '';
        $this->restart = $rendered['restart'] ?? '';
        $this->atomic_release = (string) ($this->site->deploy_strategy ?? 'simple') === 'atomic';
        $this->managed_restart_enabled = ! (bool) data_get($this->site->meta, 'deploy.skip_managed_restart', false);
    }

    /** Discard unsaved textarea / toggle edits — reload persisted state. */
    public function discard(): void
    {
        $this->loadFromSite();
        $this->closeHookForm();
    }

    /**
     * What dply restarts automatically for this site after a deploy, so the
     * Restart card is honest about it. `items` lists each managed restart in
     * run order; mirrors {@see SiteDeployPipelineRunner::runManagedRestart()}.
     *
     * @return array{has: bool, items: list<string>, label: string}
     */
    public function managedRestartInfo(): array
    {
        if ($this->site->isCustom()) {
            return ['has' => false, 'items' => [], 'label' => __('Container apps are restarted by their runtime — dply runs no extra restart here.')];
        }

        $runtime = $this->site->runtimeKey();

        if ($runtime === 'static') {
            return ['has' => false, 'items' => [], 'label' => __('Static sites need no restart — the new files are served the moment the release activates.')];
        }

        $items = [];

        if ((bool) $this->site->octane_port || $this->site->resolvedLaravelPackageFlag('octane')) {
            $items[] = __('Octane workers — php artisan octane:reload');
        } elseif ($runtime === 'php') {
            $items[] = __('PHP-FPM — reloaded so it serves the new release');
        } else {
            $items[] = __('the app service — restarted onto the new release');
        }

        if ($this->site->resolvedLaravelPackageFlag('horizon')) {
            $items[] = __('Horizon — php artisan horizon:terminate (its supervisor relaunches it)');
        }

        if ($this->site->isLaravelFrameworkDetected()) {
            $items[] = __('queue workers — php artisan queue:restart');
        }

        return [
            'has' => true,
            'items' => $items,
            'label' => __('After every deploy, dply automatically restarts:'),
        ];
    }

    /**
     * Built-in presets: key => label + the (runtime, framework) the canonical
     * defaults are generated from.
     *
     * @return array<string, array{label: string, runtime: ?string, framework: ?string}>
     */
    public function presets(): array
    {
        return [
            'laravel' => ['label' => __('Laravel'), 'runtime' => 'php', 'framework' => 'laravel'],
            'php' => ['label' => __('Generic PHP'), 'runtime' => 'php', 'framework' => null],
            'node' => ['label' => __('Node'), 'runtime' => 'node', 'framework' => null],
            'static' => ['label' => __('Static'), 'runtime' => 'static', 'framework' => null],
            'empty' => ['label' => __('Empty'), 'runtime' => null, 'framework' => null],
        ];
    }

    public function applyPreset(string $key): void
    {
        Gate::authorize('update', $this->site);

        $presets = $this->presets();
        if (! isset($presets[$key])) {
            return;
        }

        if ($key === 'empty') {
            $this->build = $this->release = $this->restart = '';
            $this->dispatch('deploy-script-blocks-changed');
            $this->toastSuccess(__('Cleared — write your own commands, then save.'));

            return;
        }

        $preset = $presets[$key];
        $scripts = app(DeployScriptComposer::class)->preset((string) $preset['runtime'], $preset['framework']);
        $this->build = $scripts['build'] ?? '';
        $this->release = $scripts['release'] ?? '';
        $this->restart = $scripts['restart'] ?? '';
        $this->dispatch('deploy-script-blocks-changed');
        $this->toastSuccess(__(':preset preset loaded — review and save.', ['preset' => $preset['label']]));
    }

    /**
     * Searchable, runtime-aware command library per phase, sourced from the same
     * palette the visual builder uses so the two never drift. Build/Release come
     * from the typed-step catalog; Restart carries curated worker commands (the
     * typed catalog has none for the restart phase).
     *
     * @return array<string, list<array{label: string, command: string, group: string}>>
     */
    public function commandCatalog(): array
    {
        $byPhase = ['build' => [], 'release' => [], 'restart' => []];

        foreach (DeployPipelinePalette::stepCatalogFor($this->site) as $group) {
            foreach ($group['entries'] as $entry) {
                if (! ($entry['visible'] ?? true)) {
                    continue;
                }
                $cmd = $entry['command_preview'] ?? null;
                if (! is_string($cmd) || trim($cmd) === '') {
                    continue;
                }
                $phase = (string) ($entry['phase'] ?? 'build');
                if (! array_key_exists($phase, $byPhase)) {
                    continue;
                }
                $byPhase[$phase][] = [
                    'label' => (string) $entry['label'],
                    'command' => $cmd,
                    'group' => (string) ($group['label'] ?? ''),
                ];
            }
        }

        $byPhase['restart'] = [
            ['label' => __('Restart queue workers'), 'command' => 'php artisan queue:restart', 'group' => __('Workers')],
            ['label' => __('Terminate Horizon'), 'command' => 'php artisan horizon:terminate', 'group' => __('Workers')],
            ['label' => __('Restart a systemd service'), 'command' => 'sudo systemctl restart my-worker.service', 'group' => __('Services')],
            ['label' => __('Reload a systemd service'), 'command' => 'sudo systemctl reload my-worker.service', 'group' => __('Services')],
        ];

        return $byPhase;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->site);

        app(DeployScriptComposer::class)->apply($this->site, [
            'build' => $this->build,
            'release' => $this->release,
            'restart' => $this->restart,
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        data_set($meta, 'deploy.skip_managed_restart', ! $this->managed_restart_enabled);

        $this->site->update([
            'deploy_strategy' => $this->atomic_release ? 'atomic' : 'simple',
            'meta' => $meta,
        ]);

        $this->loadFromSite();
        $this->toastSuccess(__('Deploy script saved.'));
    }

    // --- Deploy hooks (shell, positional anchors) ---

    /** Pipeline that actually deploys — hooks attach here so they match what runs. */
    private function activePipelineId(): string
    {
        return $this->site->active_deploy_pipeline_id
            ?: app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($this->site)->id;
    }

    public function openAddHook(): void
    {
        Gate::authorize('update', $this->site);
        $this->resetHookForm();
        $this->hook_form_open = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'deploy-script-hook');
    }

    public function openEditHook(string $id): void
    {
        Gate::authorize('update', $this->site);
        $hook = SiteDeployHook::query()
            ->where('site_id', $this->site->id)
            ->where('hook_kind', SiteDeployHook::KIND_SHELL)
            ->whereIn('anchor', self::HOOK_ANCHORS)
            ->whereKey($id)
            ->first();

        if (! $hook) {
            return;
        }

        $this->editing_hook_id = (string) $hook->id;
        $this->hook_anchor = $hook->anchor;
        $this->hook_label = (string) ($hook->label ?? '');
        $this->hook_script = (string) ($hook->script ?? '');
        $this->hook_timeout = (int) ($hook->timeout_seconds ?? 900);
        $this->hook_form_open = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'deploy-script-hook');
    }

    public function saveHook(): void
    {
        Gate::authorize('update', $this->site);

        $this->validate([
            'hook_anchor' => 'required|in:'.implode(',', self::HOOK_ANCHORS),
            'hook_label' => 'nullable|string|max:120',
            'hook_script' => 'required|string|max:16000',
            'hook_timeout' => 'required|integer|min:30|max:3600',
        ]);

        $attributes = [
            'phase' => $this->hook_anchor,
            'hook_kind' => SiteDeployHook::KIND_SHELL,
            'anchor' => $this->hook_anchor,
            'anchor_step_id' => null,
            'label' => trim($this->hook_label) !== '' ? trim($this->hook_label) : null,
            'script' => $this->hook_script,
            'timeout_seconds' => $this->hook_timeout,
        ];

        if ($this->editing_hook_id !== null) {
            $hook = SiteDeployHook::query()
                ->where('site_id', $this->site->id)
                ->whereKey($this->editing_hook_id)
                ->first();
            if ($hook) {
                $hook->update($attributes);
            }
            $this->toastSuccess(__('Deploy hook updated.'));
        } else {
            SiteDeployHook::query()->create($attributes + [
                'site_id' => $this->site->id,
                'pipeline_id' => $this->activePipelineId(),
                'sort_order' => 0,
            ]);
            $this->toastSuccess(__('Deploy hook added.'));
        }

        $this->closeHookForm();
    }

    public function deleteHook(string $id): void
    {
        Gate::authorize('update', $this->site);
        SiteDeployHook::query()
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->delete();
        $this->toastSuccess(__('Hook removed.'));
    }

    public function closeHookForm(): void
    {
        $this->hook_form_open = false;
        $this->resetHookForm();
        $this->resetErrorBag();
        $this->dispatch('close-modal', 'deploy-script-hook');
    }

    private function resetHookForm(): void
    {
        $this->editing_hook_id = null;
        $this->hook_anchor = SiteDeployHook::ANCHOR_AFTER_ACTIVATE;
        $this->hook_label = '';
        $this->hook_script = '';
        $this->hook_timeout = 900;
    }

    public function render(): View
    {
        $composer = app(DeployScriptComposer::class);

        return view('livewire.sites.deploy-script', [
            'lockedSteps' => $composer->lockedSteps($this->site),
            'hooks' => $this->site->deployHooks()->get(),
            'commandCatalog' => $this->commandCatalog(),
            'hookAnchorLabels' => SiteDeployHook::anchorLabels(),
            'hookAnchorOptions' => self::HOOK_ANCHORS,
            'managedRestart' => $this->managedRestartInfo(),
        ]);
    }
}
