<?php

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Models\SiteWebserverConfigRevision;
use App\Services\Sites\WebserverConfig\SiteWebserverConfigEditorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WebserverConfig extends Component
{
    public Server $server;

    public Site $site;

    public string $mode = SiteWebserverConfigProfile::MODE_LAYERED;

    public string $before_body = '';

    public string $main_snippet_body = '';

    public string $after_body = '';

    public string $full_override_body = '';

    public ?string $local_validation_message = null;

    public ?string $remote_validation_message = null;

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    public ?string $health_hint = null;

    public bool $show_history_modal = false;

    public ?string $remote_live_config = null;

    /** @var 'before'|'main'|'after'|'full' */
    public string $active_layer = 'main';

    /** @var 'edit'|'preview'|'compare' */
    public string $content_tab = 'edit';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

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
                $this->flash_error = $hydrate['message'];
            }
        } else {
            $this->remote_live_config = $editor->fetchRemoteMainConfig($site);
        }

        $this->active_layer = $this->mode === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE ? 'full' : 'main';
    }

    public function updatedMode(string $value): void
    {
        $this->active_layer = $value === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE ? 'full' : 'main';
        $this->content_tab = 'edit';
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
        $this->flash_success = __('Draft saved.');
        $this->flash_error = null;
    }

    public function validateLocalAction(SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('view', $this->site);
        $this->resetValidation();
        $this->flash_error = null;
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
        $this->flash_error = null;
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
        $this->flash_error = null;
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

            $this->flash_success = __('Web server configuration applied.');
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
        $profile->update([
            'mode' => $this->mode,
            'before_body' => $this->before_body,
            'main_snippet_body' => $this->main_snippet_body,
            'after_body' => $this->after_body,
            'full_override_body' => $this->full_override_body,
        ]);
        $editor->saveRevision($this->site, $profile->fresh(), auth()->user(), __('Manual snapshot'));
        $this->flash_success = __('Revision saved.');
    }

    public function restoreRevision(string $revisionId, SiteWebserverConfigEditorService $editor): void
    {
        Gate::authorize('update', $this->site);
        $profile = $editor->getOrCreateProfile($this->site);
        $rev = SiteWebserverConfigRevision::query()
            ->whereKey($revisionId)
            ->where('site_webserver_config_profile_id', $profile->id)
            ->firstOrFail();

        $editor->restoreRevision($profile, $rev);
        $this->hydrateFromProfile($profile->fresh());

        $org = $this->site->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.webserver_config.restored', $this->site, null, [
                'revision_id' => $rev->id,
            ]);
        }

        $this->show_history_modal = false;
        $this->flash_success = __('Revision restored into the editor.');
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

        $revisions = collect();
        if ($profile) {
            $revisions = $profile->revisions()->latest()->limit(25)->get();
        }

        return view('livewire.sites.webserver-config', [
            'revisions' => $revisions,
            'effective_config_preview' => $effectiveConfigPreview,
            'core_changed_warning' => $coreChangedWarning,
            'config_paths' => $this->configDisplayPaths(),
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
