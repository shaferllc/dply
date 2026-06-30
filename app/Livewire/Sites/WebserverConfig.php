<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ConfigRevision;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\WebserverConfig\SiteWebserverConfigEditorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WebserverConfig extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    public string $mode = SiteWebserverConfigProfile::MODE_LAYERED;

    public string $before_body = '';

    public string $main_snippet_body = '';

    public string $after_body = '';

    public string $full_override_body = '';

    public ?string $local_validation_message = null;

    public ?string $remote_validation_message = null;

    public ?string $health_hint = null;


    public ?string $remote_live_config = null;

    /** @var 'before'|'main'|'after'|'full' */
    public string $active_layer = 'main';

    /** @var 'edit'|'preview'|'compare' */
    public string $content_tab = 'edit';

    /** Free-text filter for the "Insert snippet" picker. */
    public string $snippetSearch = '';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        // Headless sites (webserver=none) have no vhost/server block to edit.
        // The editor service throws on this case; redirect with a flash so
        // the user isn't dropped into an exception page.
        if ($site->webserver() === 'none') {
            session()->flash('info', __('This site runs without a web server, so there’s no vhost to edit. The webserver-config page does not apply here.'));

            $this->redirect(route('sites.show', [$server, $site]), navigate: true);

            return;
        }

        $editor = app(SiteWebserverConfigEditorService::class);
        $profile = $editor->getOrCreateProfile($site);
        $this->hydrateFromProfile($profile);

        if (Gate::allows('update', $site)) {
            $hydrate = $editor->hydrateEditorFromServer($site, $profile);
            if ($hydrate['ok']) {
                $this->site = $site->fresh(['server', 'webserverConfigProfile']);
                $this->hydrateFromProfile($this->site->webserverConfigProfile ?? $profile->fresh());
                if ($hydrate['remote_config'] !== null) {
                    $this->remote_live_config = $hydrate['remote_config'];
                }
            } elseif ($hydrate['message'] !== null) {
                $this->toastError($hydrate['message']);
            }
        } else {
            $this->remote_live_config = $editor->fetchRemoteMainConfig($site);
        }

        $this->active_layer = $this->mode === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE ? 'full' : 'main';

        // Only nginx has a real before/main/after snippet model. Every other
        // engine (caddy, apache, traefik, openlitespeed) is whole-file: the
        // managed vhost IS the script, so "Layered" maps to an empty snippet
        // box. Force full-file editing for those and make sure the editor shows
        // the actual config — falling back to the rendered managed config when
        // the live read over SSH wasn't available (e.g. server not reachable).
        if (! $this->supportsLayeredSnippets()) {
            $this->mode = SiteWebserverConfigProfile::MODE_FULL_OVERRIDE;
            $this->active_layer = 'full';

            if (trim($this->full_override_body) === '') {
                $this->full_override_body = $this->remote_live_config !== null && trim($this->remote_live_config) !== ''
                    ? trim($this->remote_live_config)
                    : trim($editor->effectivePreview($this->site));
            }
        }
    }

    /**
     * Only nginx ships the before/main/after layered snippet model; all other
     * engines edit a single managed file. Gates the Layered/Full-file toggle
     * and the pipeline layer buttons in the view.
     */
    public function supportsLayeredSnippets(): bool
    {
        return $this->site->webserver() === 'nginx';
    }

    public function updatedMode(string $value): void
    {
        $this->active_layer = $value === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE ? 'full' : 'main';
        $this->content_tab = 'edit';

        // Switching to "Full file" means the user wants to edit the entire vhost.
        // Seed the editor with the current file so they edit the real config
        // instead of a blank box — prefer what's live on the server, fall back to
        // the effective (pending) build that dply would write.
        if ($value === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE && trim($this->full_override_body) === '') {
            $editor = app(SiteWebserverConfigEditorService::class);
            $live = trim((string) ($this->remote_live_config ?? ''));
            $this->full_override_body = $live !== ''
                ? $live
                : trim($editor->effectivePreview($this->site, $this->draftProfile()));
        }
    }

    protected function hydrateFromProfile(SiteWebserverConfigProfile $profile): void
    {
        $this->mode = $profile->mode;
        $this->before_body = (string) ($profile->before_body ?? '');
        $this->main_snippet_body = (string) ($profile->main_snippet_body ?? '');
        if ($this->main_snippet_body === '' && $this->site->webserver() === 'nginx') {
            $this->main_snippet_body = (string) ($this->site->nginx_extra_raw ?? '');
        }
        $this->after_body = (string) ($profile->after_body ?? '');
        $this->full_override_body = (string) ($profile->full_override_body ?? '');

        // Never present nginx's before/main/after layers as a blank box — seed
        // the self-documenting defaults when the profile carries no body (e.g. a
        // legacy profile, or a server we couldn't read live).
        if ($this->site->webserver() === 'nginx') {
            if (trim($this->before_body) === '') {
                $this->before_body = SiteWebserverConfigProfile::DEFAULT_BEFORE_BODY;
            }
            if (trim($this->main_snippet_body) === '') {
                $this->main_snippet_body = SiteWebserverConfigProfile::DEFAULT_MAIN_SNIPPET_BODY;
            }
            if (trim($this->after_body) === '') {
                $this->after_body = SiteWebserverConfigProfile::DEFAULT_AFTER_BODY;
            }
        }
    }

    protected function draftProfile(): SiteWebserverConfigProfile
    {
        $editor = app(SiteWebserverConfigEditorService::class);
        $p = $editor->getOrCreateProfile($this->site);
        $p->mode = $this->mode;
        $p->before_body = $this->before_body;
        $p->main_snippet_body = $this->main_snippet_body;
        $p->after_body = $this->after_body;
        $p->full_override_body = $this->full_override_body;

        return $p;
    }

    public function saveDraft(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('update', $this->site);
        $profile = $editor->getOrCreateProfile($this->site);
        $profile->update([
            'mode' => $this->mode,
            'before_body' => $this->before_body,
            'main_snippet_body' => $this->main_snippet_body,
            'after_body' => $this->after_body,
            'full_override_body' => $this->full_override_body,
            'draft_saved_at' => now(),
        ]);
        $this->toastSuccess(__('Draft saved.'));
    }

    public function validateLocalAction(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('view', $this->site);
        $this->resetValidation();
        $pending = $editor->effectivePreview($this->site, $this->draftProfile());
        $r = $editor->validateLocal($this->site, $pending);
        $this->local_validation_message = $r['message'];
        if (! $r['ok']) {
            $this->addError('local', $r['message']);
        }
    }

    public function validateRemoteAction(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('view', $this->site);
        $this->resetValidation();
        $profile = $this->draftProfile();
        $pending = $editor->effectivePreview($this->site, $profile);
        $r = $editor->validateRemote($this->site, $pending, $profile);
        $this->remote_validation_message = $r['message'];
        if (! $r['ok']) {
            $this->addError('remote', $r['message']);
        }
    }

    public function apply(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('update', $this->site);
        $this->resetValidation();
        $this->health_hint = null;

        $lock = $editor->lock($this->site);
        if (! $lock->get()) {
            $this->addError('apply', __('Another config apply is in progress. Try again in a moment.'));

            return;
        }

        try {
            $profile = $editor->getOrCreateProfile($this->site);
            $profile->update([
                'mode' => $this->mode,
                'before_body' => $this->before_body,
                'main_snippet_body' => $this->main_snippet_body,
                'after_body' => $this->after_body,
                'full_override_body' => $this->full_override_body,
            ]);

            $remote = $editor->validateRemote($this->site, $editor->effectivePreview($this->site->fresh(), $profile->fresh()), $profile->fresh());
            if (! $remote['ok']) {
                $this->addError('apply', $remote['message']);

                return;
            }

            $out = $editor->applyAndRecord($this->site->fresh(['server']), $profile->fresh());

            $org = $this->site->organization;
            if ($org) {
                audit_log($org, auth()->user(), 'site.webserver_config.applied', $this->site->fresh(), null, [
                    'webserver' => $this->site->webserver(),
                    'output_excerpt' => Str::limit($out, 500),
                ]);
            }

            $this->toastSuccess(__('Web server configuration applied.'));
            $hint = $editor->optionalHttpHealthHint($this->site->fresh());
            if ($hint !== null) {
                $this->health_hint = ($hint['ok'] ?? false)
                    ? __('HTTP check: :url responded with :status.', ['url' => $hint['url'] ?? '', 'status' => (string) ($hint['status'] ?? '')])
                    : __('HTTP check failed for :url.', ['url' => $hint['url'] ?? '']).' '.(string) ($hint['error'] ?? '');
            }
        } catch (\Throwable $e) {
            $this->addError('apply', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    public function saveRevision(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('update', $this->site);
        $profile = $editor->getOrCreateProfile($this->site);

        // Baseline-on-first-save: capture whatever was persisted on the profile
        // before this save so the user always has a "before" to roll back to.
        if (! $editor->streamHasRevisions($this->site)) {
            $editor->captureProfileSnapshot($this->site, [
                'mode' => $profile->mode,
                'before_body' => $profile->before_body,
                'main_snippet_body' => $profile->main_snippet_body,
                'after_body' => $profile->after_body,
                'full_override_body' => $profile->full_override_body,
            ], auth()->user(), __('Baseline (auto-captured)'));
        }

        $profile->update([
            'mode' => $this->mode,
            'before_body' => $this->before_body,
            'main_snippet_body' => $this->main_snippet_body,
            'after_body' => $this->after_body,
            'full_override_body' => $this->full_override_body,
        ]);

        $editor->captureProfileSnapshot($this->site, [
            'mode' => $this->mode,
            'before_body' => $this->before_body,
            'main_snippet_body' => $this->main_snippet_body,
            'after_body' => $this->after_body,
            'full_override_body' => $this->full_override_body,
        ], auth()->user(), __('Manual snapshot'));

        $this->toastSuccess(__('Revision saved.'));
    }

    public function restoreRevision(string $revisionId, SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('update', $this->site);
        $profile = $editor->getOrCreateProfile($this->site);
        $rev = ConfigRevision::query()
            ->whereKey($revisionId)
            ->where('stream_key', $editor->profileStreamKey($this->site))
            ->firstOrFail();

        $editor->restoreRevision($profile, $rev);
        $this->hydrateFromProfile($profile->fresh());

        $org = $this->site->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.webserver_config.restored', $this->site, null, [
                'revision_id' => $rev->id,
            ]);
        }

        $this->dispatch('close-modal', 'webserver-history-modal');
        $this->toastSuccess(__('Revision restored into the editor.'));
    }

    public function fetchRemoteConfig(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('view', $this->site);
        $this->remote_live_config = $editor->fetchRemoteMainConfig($this->site);
        if ($this->remote_live_config === null) {
            $this->addError('remote_fetch', __('Could not read the config file from the server (SSH).'));
        }
    }

    public function downloadEffective(SiteWebserverConfigEditorService $editor): mixed
    {
        Gate::authorize('view', $this->site);
        $ext = match ($this->site->webserver()) {
            'caddy' => 'caddy',
            'traefik' => 'yml',
            'openlitespeed' => 'conf',
            default => 'conf',
        };
        $name = $this->site->webserverConfigBasename().'.'.$ext;
        $body = $editor->effectivePreview($this->site, $this->draftProfile());

        return response()->streamDownload(static function () use ($body): void {
            echo $body;
        }, $name, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        $this->site->loadMissing(['server', 'domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'webserverConfigProfile']);

        $editor = app(SiteWebserverConfigEditorService::class);
        $effectiveConfigPreview = $editor->effectivePreview($this->site, $this->draftProfile());

        $profile = $this->site->webserverConfigProfile;
        $coreChangedWarning = $profile && $profile->last_applied_core_hash !== null
            && $editor->coreChangedSinceApply($this->site, $profile);

        $revisions = ConfigRevision::query()
            ->forStream($editor->profileStreamKey($this->site))
            ->limit(25)
            ->get();

        $engine = $this->site->webserver();
        $needle = mb_strtolower(trim($this->snippetSearch));
        $snippets = collect(config('webserver_snippets', []))
            ->filter(function (array $s) use ($engine, $needle): bool {
                $engines = is_array($s['webservers'] ?? null) ? $s['webservers'] : [];
                if (! in_array($engine, $engines, true)) {
                    return false;
                }
                if ($needle !== '') {
                    return str_contains(mb_strtolower((string) ($s['name'] ?? '')), $needle)
                        || str_contains(mb_strtolower((string) ($s['description'] ?? '')), $needle);
                }

                return true;
            })
            ->map(fn (array $s, string $k) => [
                'key' => $k,
                'name' => $s['name'] ?? $k,
                'description' => $s['description'] ?? '',
                'content' => (string) ($s['content'] ?? ''),
            ])
            ->values();

        // Placeholder tokens the operator can drop into the config and fill in
        // later. We insert the literal {{TOKEN}} (user's choice), but show this
        // site's resolved value as a hint so they know what each maps to.
        $phpVersion = (string) (data_get($this->site->server?->meta, 'php_version') ?: '8.x');
        $primaryDomain = (string) ($this->site->domains->first()?->hostname
            ?: (is_array($this->site->meta['testing_hostname'] ?? null)
                ? (string) ($this->site->meta['testing_hostname']['hostname'] ?? '')
                : '')
            ?: $this->site->name);
        $placeholders = [
            ['token' => '{{DOMAIN}}', 'label' => __('Primary domain'), 'example' => $primaryDomain],
            ['token' => '{{DOCROOT}}', 'label' => __('Document root'), 'example' => (string) $this->site->document_root],
            ['token' => '{{PHP_FPM_SOCKET}}', 'label' => __('PHP-FPM socket'), 'example' => 'unix///run/php/php'.$phpVersion.'-fpm.sock'],
            ['token' => '{{APP_HOST}}', 'label' => __('Upstream app host'), 'example' => '127.0.0.1'],
            ['token' => '{{APP_PORT}}', 'label' => __('Upstream app port'), 'example' => (string) ($this->site->app_port ?: $this->site->internal_port ?: '3000')],
            ['token' => '{{SERVER_IP}}', 'label' => __('Server IP'), 'example' => (string) ($this->site->server?->ip_address ?? '')],
        ];

        return view('livewire.sites.webserver-config', [
            'revisions' => $revisions,
            'effective_config_preview' => $effectiveConfigPreview,
            'core_changed_warning' => $coreChangedWarning,
            'config_paths' => $this->configDisplayPaths(),
            'webserverSnippets' => $snippets,
            'configPlaceholders' => $placeholders,
        ]);
    }

    /**
     * @return array{engine_label: string, main_vhost: string, before_layer: ?string, after_layer: ?string}
     */
    protected function configDisplayPaths(): array
    {
        $basename = $this->site->webserverConfigBasename();

        return match ($this->site->webserver()) {
            'nginx' => [
                'engine_label' => 'NGINX',
                'main_vhost' => rtrim(config('sites.nginx_sites_available'), '/').'/'.$basename.'.conf',
                'before_layer' => rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename.'/before/10-dply-layer.conf',
                'after_layer' => rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename.'/after/10-dply-layer.conf',
            ],
            'apache' => [
                'engine_label' => 'Apache',
                'main_vhost' => rtrim(config('sites.apache_sites_available'), '/').'/'.$basename.'.conf',
                'before_layer' => null,
                'after_layer' => null,
            ],
            'caddy' => [
                'engine_label' => 'Caddy',
                'main_vhost' => rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$basename.'.caddy',
                'before_layer' => null,
                'after_layer' => null,
            ],
            'traefik' => [
                'engine_label' => 'Traefik',
                'main_vhost' => rtrim(config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml',
                'before_layer' => null,
                'after_layer' => null,
            ],
            'openlitespeed' => [
                'engine_label' => 'OpenLiteSpeed',
                'main_vhost' => rtrim(config('sites.openlitespeed_vhosts_path'), '/').'/'.$basename.'/vhconf.conf',
                'before_layer' => null,
                'after_layer' => null,
            ],
            default => [
                'engine_label' => strtoupper((string) $this->site->webserver()),
                'main_vhost' => $basename,
                'before_layer' => null,
                'after_layer' => null,
            ],
        };
    }
}
