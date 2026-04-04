<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Models\SiteWebserverConfigRevision;
use App\Models\User;
use App\Services\Sites\SiteApacheProvisioner;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use App\Services\Sites\SiteTraefikProvisioner;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SiteWebserverConfigEditorService
{
    public const APPLY_LOCK_KEY = 'site-webserver-config-apply';

    public function __construct(
        private readonly WebserverConfigEngineRegistry $registry,
        private readonly SiteWebserverConfigApplier $applier,
        private readonly SiteNginxProvisioner $nginxProvisioner,
        private readonly SiteApacheProvisioner $apacheProvisioner,
        private readonly SiteCaddyProvisioner $caddyProvisioner,
        private readonly SiteOpenLiteSpeedProvisioner $openLiteSpeedProvisioner,
        private readonly SiteTraefikProvisioner $traefikProvisioner,
    ) {}

    public function lock(Site $site): Lock
    {
        return Cache::lock(self::APPLY_LOCK_KEY.':'.$site->id, 120);
    }

    public function getOrCreateProfile(Site $site): SiteWebserverConfigProfile
    {
        $ws = $site->webserver();

        return SiteWebserverConfigProfile::query()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'webserver' => $ws,
                'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
                'main_snippet_body' => $ws === 'nginx' ? $site->nginx_extra_raw : null,
            ]
        );
    }

    public function effectivePreview(Site $site, ?SiteWebserverConfigProfile $profile = null): string
    {
        $profile ??= $site->webserverConfigProfile;
        $raw = $this->registry->for($site->webserver())->effectiveConfig($site, $profile);

        return is_string($raw) ? $raw : '';
    }

    public function managedCoreHash(Site $site): string
    {
        return $this->registry->for($site->webserver())->managedCoreHash($site);
    }

    public function coreChangedSinceApply(Site $site, SiteWebserverConfigProfile $profile): bool
    {
        $current = $this->managedCoreHash($site);
        $stored = $profile->last_applied_core_hash;

        return $stored !== null && $stored !== $current;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function validateLocal(Site $site, string $config): array
    {
        return $this->registry->for($site->webserver())->validateLocal($config);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array
    {
        return $this->registry->for($site->webserver())->validateRemote($site, $config, $profile);
    }

    /**
     * Apply managed web server config on the host (reload). Updates profile checksums when successful.
     */
    public function applyAndRecord(Site $site, SiteWebserverConfigProfile $profile): string
    {
        if ($site->webserver() === 'nginx') {
            $site->update(['nginx_extra_raw' => $profile->main_snippet_body]);
        }

        $out = $this->applier->apply($site->fresh(['server']));

        $site = $site->fresh(['server']);
        $profile = $profile->fresh();
        $effective = $this->effectivePreview($site, $profile);

        $profile->update([
            'last_applied_effective_checksum' => hash('sha256', $effective),
            'last_applied_core_hash' => $this->managedCoreHash($site),
            'last_applied_at' => now(),
        ]);

        return $out;
    }

    public function saveRevision(Site $site, SiteWebserverConfigProfile $profile, User $user, ?string $summary = null): SiteWebserverConfigRevision
    {
        $snapshot = [
            'mode' => $profile->mode,
            'before_body' => $profile->before_body,
            'main_snippet_body' => $profile->main_snippet_body,
            'after_body' => $profile->after_body,
            'full_override_body' => $profile->full_override_body,
        ];

        return SiteWebserverConfigRevision::query()->create([
            'site_webserver_config_profile_id' => $profile->id,
            'user_id' => $user->id,
            'summary' => $summary,
            'snapshot' => $snapshot,
            'checksum' => hash('sha256', json_encode($snapshot)),
        ]);
    }

    public function restoreRevision(SiteWebserverConfigProfile $profile, SiteWebserverConfigRevision $revision): void
    {
        $snap = $revision->snapshot;
        $profile->update([
            'mode' => $snap['mode'] ?? SiteWebserverConfigProfile::MODE_LAYERED,
            'before_body' => $snap['before_body'] ?? null,
            'main_snippet_body' => $snap['main_snippet_body'] ?? null,
            'after_body' => $snap['after_body'] ?? null,
            'full_override_body' => $snap['full_override_body'] ?? null,
        ]);
    }

    public function fetchRemoteMainConfig(Site $site): ?string
    {
        try {
            return match ($site->webserver()) {
                'nginx' => $this->nginxProvisioner->readCurrentMainConfig($site),
                'apache' => $this->apacheProvisioner->readCurrentSiteConfig($site),
                'caddy' => $this->caddyProvisioner->readCurrentSiteConfig($site),
                'openlitespeed' => $this->openLiteSpeedProvisioner->readCurrentSiteConfig($site),
                'traefik' => $this->traefikProvisioner->readCurrentDynamicConfig($site),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pull the live config file(s) from the host into the profile and related site fields.
     *
     * @return array{ok: bool, message: ?string, remote_config: ?string}
     */
    public function hydrateEditorFromServer(Site $site, SiteWebserverConfigProfile $profile): array
    {
        $site->loadMissing('server');

        if (! $this->serverAllowsRemoteConfigRead($site)) {
            return ['ok' => false, 'message' => null, 'remote_config' => null];
        }

        try {
            return match ($site->webserver()) {
                'nginx' => $this->hydrateNginxEditorFromServer($site, $profile),
                'apache' => $this->hydrateSingleFileOverrideFromServer(
                    $site,
                    $profile,
                    fn (): ?string => $this->apacheProvisioner->readCurrentSiteConfig($site)
                ),
                'caddy' => $this->hydrateSingleFileOverrideFromServer(
                    $site,
                    $profile,
                    fn (): ?string => $this->caddyProvisioner->readCurrentSiteConfig($site)
                ),
                'openlitespeed' => $this->hydrateSingleFileOverrideFromServer(
                    $site,
                    $profile,
                    fn (): ?string => $this->openLiteSpeedProvisioner->readCurrentSiteConfig($site)
                ),
                'traefik' => $this->hydrateSingleFileOverrideFromServer(
                    $site,
                    $profile,
                    fn (): ?string => $this->traefikProvisioner->readCurrentDynamicConfig($site)
                ),
                default => ['ok' => false, 'message' => null, 'remote_config' => null],
            };
        } catch (\Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => __('Could not read the live configuration from the server.'),
                'remote_config' => null,
            ];
        }
    }

    /**
     * @return array{ok: bool, message: ?string, remote_config: ?string}
     */
    protected function hydrateNginxEditorFromServer(Site $site, SiteWebserverConfigProfile $profile): array
    {
        $main = $this->nginxProvisioner->readCurrentMainConfig($site);
        if ($main === null || trim($main) === '') {
            return [
                'ok' => false,
                'message' => __('The nginx vhost file was missing or empty on the server.'),
                'remote_config' => null,
            ];
        }

        $basename = $site->nginxConfigBasename();
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $isLayeredRemote = str_contains($main, $base.'/before/') && str_contains($main, $base.'/after/');

        $profileWantsLayered = $profile->mode === SiteWebserverConfigProfile::MODE_LAYERED;
        $wantsLayered = $profileWantsLayered || $isLayeredRemote;

        if ($wantsLayered) {
            // ensureNginxLayerSnippetFilesIfMissing only runs when the profile is layered; if the host
            // already has Dply includes but the app still thought "full file", flip mode first.
            if ($isLayeredRemote && $profile->mode !== SiteWebserverConfigProfile::MODE_LAYERED) {
                $profile->update(['mode' => SiteWebserverConfigProfile::MODE_LAYERED]);
                $profile->refresh();
            }

            $this->nginxProvisioner->ensureNginxLayerSnippetFilesIfMissing($site, $profile);

            $beforeRaw = $this->nginxProvisioner->readLayerSnippetFile($site, 'before');
            $afterRaw = $this->nginxProvisioner->readLayerSnippetFile($site, 'after');

            if ($isLayeredRemote) {
                $parsedMain = $this->nginxProvisioner->parseLayeredMainSnippetFromVhost($site, $main);
                $mainSnippet = $parsedMain !== null
                    ? $parsedMain
                    : trim((string) ($profile->main_snippet_body ?? $site->nginx_extra_raw ?? ''));
            } else {
                $mainSnippet = trim((string) ($profile->main_snippet_body ?? ''));
                if ($mainSnippet === '') {
                    $mainSnippet = (string) ($site->nginx_extra_raw ?? '');
                }
            }

            $profile->update([
                'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
                'before_body' => $this->normalizeNginxLayerPlaceholder($beforeRaw, 'before'),
                'after_body' => $this->normalizeNginxLayerPlaceholder($afterRaw, 'after'),
                'main_snippet_body' => $mainSnippet,
                'full_override_body' => null,
            ]);
            $site->update(['nginx_extra_raw' => $mainSnippet]);

            return [
                'ok' => true,
                'message' => __('Loaded live web server configuration from the server.'),
                'remote_config' => $main,
            ];
        }

        $profile->update([
            'mode' => SiteWebserverConfigProfile::MODE_FULL_OVERRIDE,
            'full_override_body' => trim($main),
            'before_body' => null,
            'main_snippet_body' => null,
            'after_body' => null,
        ]);
        $site->update(['nginx_extra_raw' => '']);

        return [
            'ok' => true,
            'message' => __('Loaded live web server configuration from the server.'),
            'remote_config' => $main,
        ];
    }

    /**
     * @return array{ok: bool, message: ?string, remote_config: ?string}
     */
    protected function hydrateSingleFileOverrideFromServer(Site $site, SiteWebserverConfigProfile $profile, callable $read): array
    {
        $body = $read();
        if ($body === null || trim($body) === '') {
            return [
                'ok' => false,
                'message' => __('The config file was missing or empty on the server.'),
                'remote_config' => null,
            ];
        }

        $profile->update([
            'mode' => SiteWebserverConfigProfile::MODE_FULL_OVERRIDE,
            'full_override_body' => trim($body),
            'before_body' => null,
            'main_snippet_body' => null,
            'after_body' => null,
        ]);

        return [
            'ok' => true,
            'message' => __('Loaded live web server configuration from the server.'),
            'remote_config' => $body,
        ];
    }

    protected function serverAllowsRemoteConfigRead(Site $site): bool
    {
        $server = $site->server;
        if (! $server || ! $server->isReady()) {
            return false;
        }

        if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            return false;
        }

        $key = $server->ssh_private_key;

        if ($key === null || $key === '') {
            return false;
        }

        return $server->hostCapabilities()->supportsSsh();
    }

    protected function normalizeNginxLayerPlaceholder(?string $raw, string $kind): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }

        $placeholder = $kind === 'before'
            ? '# Dply placeholder (empty before layer)'
            : '# Dply placeholder (empty after layer)';

        return trim($raw) === $placeholder ? '' : $raw;
    }

    /**
     * @return array{ok: bool, url?: string, status?: int, error?: string}|null
     */
    public function optionalHttpHealthHint(Site $site): ?array
    {
        $site->loadMissing('domains');
        $host = optional($site->primaryDomain())->hostname;
        if ($host === null || $host === '') {
            $names = $site->webserverHostnames();
            $host = $names[0] ?? null;
        }
        if ($host === null || $host === '') {
            return null;
        }

        $url = 'http://'.strtolower($host);

        try {
            $response = Http::timeout(5)->withoutVerifying()->head($url);

            return [
                'ok' => $response->successful(),
                'url' => $url,
                'status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }
}
