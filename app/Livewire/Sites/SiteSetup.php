<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\PreflightSiteSetupJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\ManagesSiteEnvironment;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Post-repo-connect SETUP WIZARD for import/preset VM sites — the guided
 * "now configure your site" flow that runs after a repo is connected. Rendered
 * as the Repository "Set up" tab (embedded) while the site is held for first
 * deploy; the site stays LIVE (splash serving) throughout.
 *
 * Two steps:
 *   1. Environment — IS the real Environment tab (the {@see ManagesSiteEnvironment}
 *      + {@see ManagesSiteBindings} editor rendered from
 *      `settings/partials/environment.blade.php`): variables with masked
 *      Show/Edit rows, prefix filter chips, import, AND "Connect resource"
 *      (attach/provision databases, cache, queue, storage). The same surface as
 *      Deployments → Environment, so there's one editor, zero drift.
 *   2. Review — confirm repo/runtime, soft-warn on unset boot-critical vars
 *      ("Deploy anyway"), and dispatch the first deploy.
 *
 * Env edits write only to the encrypted cache during setup — the SSH push is
 * HELD until deploy (see {@see autoPushAfterCacheMutation}); the first deploy
 * composes the .env from the cache.
 *
 * Lifecycle is driven by meta.setup.state (written by
 * {@see PreflightSiteSetupJob}): 'scanning' shows an analyzing timeline; a clean
 * scan flips to 'deploying' and we bounce to the live site; 'needs_setup' /
 * 'scan_failed' land in the steps.
 */
#[Layout('layouts.app')]
class SiteSetup extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesSiteBindings;
    use ManagesSiteEnvironment;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    /**
     * When true, the wizard renders inside another page (the Repository "Set up"
     * tab) — suppress its own breadcrumb / sidebar / page chrome so the host
     * provides the framing. Mirrors {@see Repository::$embedded}.
     */
    public bool $embedded = false;

    /** Active step: 'environment' | 'review'. */
    #[Url(as: 'step', except: '')]
    public string $step = '';

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

        if (! in_array($this->step, ['environment', 'review'], true)) {
            $this->step = 'environment';
        }
    }

    /**
     * HOLD the .env push until deploy. The real Environment tab pushes every
     * edit to the server's live .env over SSH; during first-deploy setup the app
     * isn't deployed yet, so we write only to the encrypted cache and let the
     * first deploy compose the .env. Overrides {@see ManagesSiteEnvironment::autoPushAfterCacheMutation}.
     */
    protected function autoPushAfterCacheMutation(string $savedMessage): void
    {
        $this->toastSuccess($savedMessage.' '.__('Saved — applies on first deploy.'));
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

        if (! $this->site->isPreflightScanning() && $this->step === '') {
            $this->step = 'environment';
        }
    }

    /**
     * Re-run the pre-flight scan — after a scan failure (bad URL / access
     * fixed) OR when a scan stalls (the job died mid-run and the wizard is
     * stuck polling). Resets the heartbeat so the analyzing timeline restarts.
     */
    public function rescan(): void
    {
        Gate::authorize('update', $this->server);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['setup'] = [
            'state' => 'scanning',
            'started_at' => now()->toISOString(),
            'scan_step' => 'resolving',
            'scan_step_at' => now()->toISOString(),
        ];
        $this->site->forceFill(['meta' => $meta])->save();
        $this->site->refresh();

        // A crashed pre-flight job can leave its ShouldBeUnique lock held, which
        // would silently swallow this re-dispatch. Force-release it first so the
        // re-scan always actually enqueues.
        (new \Illuminate\Bus\UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class)))
            ->release(new PreflightSiteSetupJob($this->site->id, (string) auth()->id()));

        PreflightSiteSetupJob::dispatch($this->site->id, (string) auth()->id());
    }

    /** Escape hatch — leave setup for the live site; the held state persists. */
    public function configureLater(): mixed
    {
        return $this->redirectRoute('sites.show', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);
    }

    public function goToStep(string $step): void
    {
        if (in_array($step, ['environment', 'review'], true)) {
            $this->step = $step;
        }
    }

    /** Final action: dispatch the first deploy (which composes .env from the cache). */
    public function finishAndDeploy(SiteDeployPipelineManager $pipeline, SiteDeploySyncCoordinator $coordinator): mixed
    {
        Gate::authorize('update', $this->server);

        // We do NOT hard-block on unset required vars: the Review step warns and
        // the operator can deploy anyway and let it fail — their call. The env is
        // already persisted in the cache (the editor writes it directly).
        $this->site->refresh();

        // Guarantee a Laravel APP_KEY at the LAST moment — only when still empty,
        // so a verbatim worker import (which carries the worker's key) or a
        // manually-generated key is never clobbered. The VM deploy pipeline does
        // NOT run `key:generate`, so without this a Laravel app boots keyless and
        // 500s. Mirrors ServerlessEnvironmentPreparer for the VM path.
        $this->ensureLaravelAppKey();
        $this->site->refresh();

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['setup'] = ['state' => 'deploying', 'deployed_at' => now()->toISOString()];
        $this->site->forceFill(['meta' => $meta])->save();

        $fresh = $this->site->fresh() ?? $this->site;
        $pipeline->seedRuntimeDefaults($fresh, (string) $fresh->runtime ?: 'php');
        $coordinator->dispatchManualForGroup($fresh->fresh() ?? $fresh);

        // Land on the deploy screen so the operator watches the first deploy run,
        // not the bare site dashboard.
        return $this->redirectRoute('sites.deployments.index', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);
    }

    /**
     * Mint a Laravel APP_KEY into the env cache iff the app wants one (it's
     * declared in the .env or scanned from code) AND it's still empty — so an
     * imported/worker key or a manually-generated one is never overwritten.
     * {@see freshAppKey()} comes from {@see ManagesSiteEnvironment}.
     */
    private function ensureLaravelAppKey(): void
    {
        $parser = app(\App\Services\Sites\DotEnvFileParser::class);
        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $vars = is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];

        $wantsKey = array_key_exists('APP_KEY', $vars)
            || collect(data_get($this->site->envRequirements(), 'keys', []))
                ->contains(fn ($k) => is_array($k) && ($k['key'] ?? '') === 'APP_KEY');

        if (! $wantsKey || trim((string) ($vars['APP_KEY'] ?? '')) !== '') {
            return; // not a Laravel app, or a key is already set (imported / generated)
        }

        $vars['APP_KEY'] = $this->freshAppKey();
        $this->site->forceFill([
            'env_file_content' => app(\App\Services\Sites\DotEnvFileWriter::class)->render($vars, $parsed['comments'] ?? []),
            'env_cache_origin' => 'local-edit',
        ])->save();
    }

    /** Boot-critical keys still unsatisfied — the "N left" count and the Review soft gate. */
    public function missingRequired(): array
    {
        return $this->site->unsatisfiedBootCriticalEnvKeys();
    }

    public function render(): View
    {
        // Non-embedded chrome (breadcrumb/sidebar) needs the shared settings
        // sidebar payload; mirror Commits/Repository so it never 500s on
        // $resourceNoun. (Embedded mode skips the sidebar entirely.)
        $runtimeMode = $this->site->runtimeTargetMode();

        return view('livewire.sites.site-setup', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'setup',
        ]);
    }
}
