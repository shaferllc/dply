<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPushScheduler;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A lightweight "quick edit .env" slide-over, mounted next to the persistent
 * Deploy button in the breadcrumb chrome so the whole .env can be edited — and
 * pushed — from ANY site-workspace page without navigating to the Environment
 * page. Resolves the site from the route, mirroring {@see DeployControl}.
 *
 * The drawer edits the full env cache as raw text (a complete rewrite, exactly
 * like the Environment page's "Edit all"), then coalesces the server push
 * through {@see SiteEnvPushScheduler}. For anything richer — per-variable
 * reveal/override, resource bindings, requirement scans — the full Environment
 * page ({@see SiteEnvironment}) stays the home; this is just the fast path.
 */
class EnvQuickEdit extends Component
{
    use DispatchesToastNotifications;
    use WatchesConsoleActionOutcomes;

    public ?Site $site = null;

    public ?Server $server = null;

    /** Editable buffer for the drawer — the full .env as text. */
    public string $env_text = '';

    public function mount(): void
    {
        $site = request()->route('site');
        $server = request()->route('server');

        $this->site = $site instanceof Site ? $site : null;
        $this->server = $server instanceof Server ? $server : $this->site?->server;
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->site !== null
            && $this->server !== null
            && $this->server->isVmHost()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime()
            && Gate::allows('update', $this->site);
    }

    /** Pull the current .env into the buffer whenever the drawer opens. */
    public function loadEnv(): void
    {
        if (! $this->canEdit()) {
            return;
        }
        $this->resetErrorBag('env_text');
        $this->env_text = (string) ($this->site->env_file_content ?? '');
    }

    /**
     * Full rewrite of the env cache from the textarea, then a coalesced push.
     * Mirrors {@see ManagesSiteEnvCrud::saveAllEnv()} + autoPushAfterCacheMutation
     * so both surfaces write, audit, and push the .env identically.
     */
    public function save(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        if (! $this->canEdit()) {
            return;
        }
        Gate::authorize('update', $this->site);
        $this->validate(['env_text' => 'nullable|string|max:200000']);

        $parsed = $parser->parse($this->env_text);
        if ($parsed['errors'] !== []) {
            foreach ($parsed['errors'] as $err) {
                $this->addError('env_text', $err);
            }

            return;
        }

        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.bulk_imported', $this->site, null, [
                'imported_count' => count($parsed['variables']),
                'imported_keys' => array_keys($parsed['variables']),
            ]);
        }

        $saved = __('Environment replaced — :count variable(s).', ['count' => count($parsed['variables'])]);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastSuccess($saved.' '.__('Saved.'));
            $this->dispatch('env-quick-edit-saved');

            return;
        }

        $scheduled = app(SiteEnvPushScheduler::class)->schedule($this->site, (string) (auth()->id() ?? ''));
        $this->watchConsoleAction(
            $scheduled['run'],
            $saved.' '.__('Pushed to server.'),
            __('Push to server did not finish.'),
        );
        $this->toastSuccess($scheduled['coalesced']
            ? $saved.' '.__('Queued with the pending push to the server.')
            : $saved.' '.__('Pushing to server — this finishes in the background.'));

        $this->dispatch('env-quick-edit-saved');
    }

    public function render()
    {
        return view('livewire.sites.env-quick-edit');
    }
}
