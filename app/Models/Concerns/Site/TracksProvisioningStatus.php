<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\SiteWorkerPageBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 */
trait TracksProvisioningStatus
{
    /**
     * Whether this site can export a full filesystem archive over SSH (BYO VM-style hosts only).
     */
    public function supportsSshFileArchive(): bool
    {
        if ($this->usesFunctionsRuntime()
            || $this->usesDockerRuntime()
            || $this->usesKubernetesRuntime()) {
            return false;
        }

        $server = $this->server;

        return $server !== null
            && $server->isReady()
            && $server->hasAnySshPrivateKey();
    }

    public function currentSslSummary(): string
    {
        $certificates = $this->relationLoaded('certificates')
            ? $this->certificates
            : $this->certificates()->get();

        if ($certificates->contains('status', SiteCertificate::STATUS_ACTIVE)) {
            return self::SSL_ACTIVE;
        }

        if ($certificates->contains('status', SiteCertificate::STATUS_PENDING)
            || $certificates->contains('status', SiteCertificate::STATUS_ISSUED)
            || $certificates->contains('status', SiteCertificate::STATUS_INSTALLING)) {
            return self::SSL_PENDING;
        }

        if ($certificates->contains('status', SiteCertificate::STATUS_FAILED)) {
            return self::SSL_FAILED;
        }

        return $this->ssl_status;
    }

    /**
     * A headless site runs deployed code with no HTTP front (no webserver,
     * no domain, no SSL) — e.g. a queue-worker host where webserver=none.
     * It still uses the full standard deploy pipeline (git, build, releases),
     * just skips the vhost / testing-hostname / reachability steps.
     */
    public function isHeadless(): bool
    {
        return $this->webserver() === 'none';
    }

    /**
     * A worker site lives on a worker host (server_role=worker). Unlike a
     * headless site it still runs Caddy (so it can attach a testing URL), but
     * it only runs queue workers from the deployed code and never serves a web
     * app. The webserver therefore locks the URL down to a static "this runs
     * workers" page instead of exposing the deployed code — see
     * {@see CaddySiteConfigBuilder} and
     * {@see SiteWorkerPageBuilder}.
     */
    public function isWorkerSite(): bool
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        // Explicit per-site override wins when set (the user toggle). Absent an
        // override, worker mode defaults ON for sites on a worker host and OFF
        // everywhere else.
        if (array_key_exists('worker_mode', $meta) && $meta['worker_mode'] !== null) {
            return (bool) $meta['worker_mode'];
        }

        return $this->server?->isWorkerHost() === true;
    }

    /**
     * Whether worker mode is an explicit user choice (meta override) rather than
     * the host-role default. Lets the UI show the toggle in its real state.
     */
    public function workerModeIsExplicit(): bool
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        return array_key_exists('worker_mode', $meta) && $meta['worker_mode'] !== null;
    }

    public function provisioningMeta(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $provisioning = $meta['provisioning'] ?? [];

        return is_array($provisioning) ? $provisioning : [];
    }

    /**
     * @return list<array{
     *     at?: string,
     *     level?: string,
     *     step?: string,
     *     message?: string,
     *     context?: array<string, mixed>
     * }>
     */
    public function provisioningLog(): array
    {
        $log = $this->provisioningMeta()['log'] ?? [];

        return collect(is_array($log) ? $log : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    public function provisioningState(): ?string
    {
        $state = $this->provisioningMeta()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public function provisioningError(): ?string
    {
        $error = $this->provisioningMeta()['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    public function provisionedHostname(): ?string
    {
        $hostname = $this->provisioningMeta()['ready_hostname'] ?? null;

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    public function provisionedUrl(): ?string
    {
        $readyUrl = $this->provisioningMeta()['ready_url'] ?? null;
        if (is_string($readyUrl) && $readyUrl !== '') {
            return $readyUrl;
        }

        $hostname = $this->provisionedHostname();

        return $hostname ? 'http://'.$hostname : null;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ! in_array($this->provisioningState(), ['ready', 'failed'], true);
    }

    public function isReadyForTraffic(): bool
    {
        return in_array($this->status, self::webserverActiveStatuses(), true)
            || in_array($this->status, [
                self::STATUS_DOCKER_ACTIVE,
                self::STATUS_KUBERNETES_ACTIVE,
                self::STATUS_FUNCTIONS_ACTIVE,
            ], true);
    }

    /**
     * @return list<string>
     */
    public static function webserverActiveStatuses(): array
    {
        return [
            self::STATUS_NGINX_ACTIVE,
            self::STATUS_APACHE_ACTIVE,
            self::STATUS_CADDY_ACTIVE,
            self::STATUS_OPENLITESPEED_ACTIVE,
            self::STATUS_TRAEFIK_ACTIVE,
        ];
    }

    public function isReadyForWorkspace(): bool
    {
        if ($this->isReadyForTraffic()) {
            return true;
        }

        if (in_array($this->status, [
            self::STATUS_DOCKER_CONFIGURED,
            self::STATUS_KUBERNETES_CONFIGURED,
            self::STATUS_FUNCTIONS_CONFIGURED,
            self::STATUS_CONTAINER_PROVISIONING,
            self::STATUS_CONTAINER_ACTIVE,
            self::STATUS_CONTAINER_FAILED,
            self::STATUS_CUSTOM_ACTIVE,
            self::STATUS_EDGE_ACTIVE,
        ], true)) {
            return true;
        }

        // Subsequent redeploys flip the site to STATUS_EDGE_PROVISIONING /
        // STATUS_EDGE_FAILED. Without this carve-out a failed-first-deploy
        // would land in the workspace showing a misleading "Open live site"
        // header (no site ever went live). Both transient + failed states
        // stay in the provisioning shell until a deploy actually publishes
        // — `active_deployment_id` is only set by PublishEdgeDeploymentJob
        // on a successful publish, so it's a reliable "has ever been live"
        // signal that persists across re-deploys.
        if (in_array($this->status, [self::STATUS_EDGE_PROVISIONING, self::STATUS_EDGE_FAILED], true)) {
            $activeDeploymentId = $this->edgeMeta()['active_deployment_id'] ?? null;
            if (is_string($activeDeploymentId) && $activeDeploymentId !== '') {
                return true;
            }
        }

        return false;
    }

    public function isCustom(): bool
    {
        return $this->type === SiteType::Custom;
    }

    public function isCustomGitMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) !== '';
    }

    public function isCustomNoRepoMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) === '';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NGINX_ACTIVE => 'nginx active',
            self::STATUS_APACHE_ACTIVE => 'apache active',
            self::STATUS_CADDY_ACTIVE => 'caddy active',
            self::STATUS_OPENLITESPEED_ACTIVE => 'openlitespeed active',
            self::STATUS_TRAEFIK_ACTIVE => 'traefik active',
            self::STATUS_DOCKER_CONFIGURED => 'docker configured',
            self::STATUS_DOCKER_ACTIVE => 'docker active',
            self::STATUS_KUBERNETES_CONFIGURED => 'kubernetes configured',
            self::STATUS_KUBERNETES_ACTIVE => 'kubernetes active',
            self::STATUS_FUNCTIONS_CONFIGURED => 'functions configured',
            self::STATUS_FUNCTIONS_ACTIVE => 'functions active',
            self::STATUS_CUSTOM_ACTIVE => 'custom active',
            default => str_replace('_', ' ', $this->status),
        };
    }

    public static function activeStatusForWebserver(string $webserver): string
    {
        return match ($webserver) {
            'apache' => self::STATUS_APACHE_ACTIVE,
            'caddy' => self::STATUS_CADDY_ACTIVE,
            'openlitespeed' => self::STATUS_OPENLITESPEED_ACTIVE,
            'traefik' => self::STATUS_TRAEFIK_ACTIVE,
            'docker' => self::STATUS_DOCKER_ACTIVE,
            'kubernetes' => self::STATUS_KUBERNETES_ACTIVE,
            'digitalocean_functions' => self::STATUS_FUNCTIONS_ACTIVE,
            // Headless (no webserver) sites are "active" once code is deployed —
            // reuse the custom-active status, which the active-status lists and
            // labels already recognise.
            'none' => self::STATUS_CUSTOM_ACTIVE,
            default => self::STATUS_NGINX_ACTIVE,
        };
    }

    /**
     * Bare site waiting for the user to pick an application on
     * sites.choose-app. Distinct from STATUS_PENDING, which means "app
     * chosen, provisioning queued".
     */
    public function isAwaitingApp(): bool
    {
        return $this->status === self::STATUS_AWAITING_APP;
    }

    /**
     * True when the workspace should force the user onto the choose-app
     * picker before showing the normal site UI. Fires while the choose-app
     * flow is enabled for any site without an application installed yet —
     * both freshly-created bare sites (STATUS_AWAITING_APP) and pre-existing
     * web sites that never had a repo or deploy. A site the user explicitly
     * "skipped" is viewable and not forced back here — see
     * {@see canRechooseApp()}.
     */
    public function needsAppChoice(): bool
    {
        if (! config('dply.choose_app_enabled')) {
            return false;
        }

        if ((bool) data_get($this->meta, 'choose_app.skipped', false)) {
            return false;
        }

        return $this->isAwaitingApp() || $this->lacksInstalledApp();
    }

    /**
     * True when the choose-app picker should remain reachable for this site:
     * it is still bare, the user picked "Blank / Skip", or it is an existing
     * web site with no application installed. A successful real install
     * (git repo set, a deploy, or a scaffold) clears this.
     */
    public function canRechooseApp(): bool
    {
        // A site already routed through the picker (awaiting an app, or created
        // services-first with "skipped") keeps its "Connect repository" path even
        // if the feature flag is later turned off — otherwise the flag would
        // strand live, app-less sites with no way to attach a repo. The flag only
        // gates NEW entries (pre-existing web sites with no app installed).
        $alreadyInFlow = $this->isAwaitingApp()
            || (bool) data_get($this->meta, 'choose_app.skipped', false);

        if (! config('dply.choose_app_enabled')) {
            return $alreadyInFlow;
        }

        return $alreadyInFlow || $this->lacksInstalledApp();
    }

    /**
     * True when this is a VM web site (PHP/Node) that has no application
     * installed yet: no git repository, never deployed, and not already
     * scaffolded or routed through the choose-app flow. Static, custom,
     * container, serverless and edge sites are excluded — they either don't
     * use a repo or have their own create flow. Sites mid-scaffold have
     * their own journey and are excluded too.
     */
    private function lacksInstalledApp(): bool
    {
        if (! in_array($this->type, [SiteType::Php, SiteType::Node], true)) {
            return false;
        }

        if ($this->usesFunctionsRuntime()
            || $this->usesDockerRuntime()
            || $this->usesKubernetesRuntime()
            || $this->usesContainerRuntime()
            || $this->usesEdgeRuntime()) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_SCAFFOLDING, self::STATUS_SCAFFOLD_FAILED], true)) {
            return false;
        }

        // Already has an application by any of these signals.
        if (data_get($this->meta, 'scaffold.framework') !== null) {
            return false;
        }
        if (data_get($this->meta, 'choose_app.chosen_kind') !== null) {
            return false;
        }
        if (is_string($this->git_repository_url) && trim($this->git_repository_url) !== '') {
            return false;
        }
        if ($this->last_deploy_at !== null) {
            return false;
        }

        return true;
    }

    public function isAtomicDeploys(): bool
    {
        return $this->deploy_strategy === 'atomic';
    }

    /**
     * Per-deploy ephemeral SSH credentials (org flag + site opt-in).
     */
    public function usesEphemeralDeployCredentials(): bool
    {
        return (bool) data_get($this->meta, 'deploy.ephemeral_credentials', false);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
