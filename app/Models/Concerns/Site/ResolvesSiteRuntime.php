<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DeploymentSecretInventory;
use App\Services\Deploy\LaravelComposerPackageDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 */
trait ResolvesSiteRuntime
{
    /**
     * Returns the version of whatever runtime this site uses.
     *
     * Prefers the new `runtime_version` column; falls back to `php_version`
     * for legacy/PHP rows that haven't been re-saved since the column was
     * introduced.
     */
    public function runtimeVersion(): ?string
    {
        $version = $this->runtime_version;
        if (is_string($version) && $version !== '') {
            return $version;
        }

        return null;
    }

    /**
     * Returns the canonical runtime key for this site (php/node/python/
     * ruby/go/static).
     *
     * Prefers the new `runtime` column; falls back to the existing `type`
     * enum for rows that predate the column. The fallback only covers the
     * three values the form historically supported (php/node/static) —
     * python/ruby/go sites can only exist via the new code path.
     */
    public function runtimeKey(): ?string
    {
        $runtime = $this->runtime;
        if (is_string($runtime) && $runtime !== '') {
            return $runtime;
        }

        return $this->type?->value;
    }

    /**
     * The PHP version this site runs on, when the runtime is PHP.
     *
     * Reads from the new `runtime_version` column (canonical source per
     * the strategy memo's "drop php_version column entirely" decision)
     * and falls back to the legacy `php_version` column for rows that
     * predate the column drop. Returns null for non-PHP runtimes so
     * call sites can distinguish "not a PHP site" from "PHP version
     * unknown".
     */
    public function phpVersion(): ?string
    {
        if ($this->runtimeKey() !== 'php') {
            return null;
        }

        $version = $this->runtime_version;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The database engine this site targets.
     *
     * Prefers the explicit `database_engine` column when set (the user
     * picked an engine on a multi-engine server), and falls back to the
     * server's default ServerDatabaseEngine row. Returns null on hosts
     * with no DB at all (cache-only / load-balancer / static-only servers).
     *
     * Per the strategy memo: "Site database_engine defaults to server's
     * default; can be overridden to any engine installed on the server."
     */
    public function databaseEngine(): ?string
    {
        if (is_string($this->database_engine) && $this->database_engine !== '') {
            return $this->database_engine;
        }

        $server = $this->server ?? Server::query()->find($this->server_id);
        if ($server === null) {
            return null;
        }

        $default = $server->defaultDatabaseEngine();

        return $default?->engine;
    }

    /**
     * Back-compat shim for the dropped `php_version` column.
     *
     * The strategy memo's "drop php_version column entirely" decision
     * removed the underlying column, but a lot of test code (and
     * possibly third-party callers) still passes `'php_version' => '8.3'`
     * to factory `->create([...])` calls or to `Site::query()->update()`.
     * Routing those through to `runtime_version` keeps the old call
     * shape working while the canonical column is the new one.
     *
     * Reads return runtime_version when the runtime is PHP, null otherwise
     * — matches phpVersion()'s semantics so consumers can call either.
     */
    public function setPhpVersionAttribute($value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($this->runtime) || $this->runtime === '') {
            $this->runtime = 'php';
        }
        $this->runtime_version = (string) $value;
    }

    public function getPhpVersionAttribute(): ?string
    {
        return $this->phpVersion();
    }

    public function runtimeProfile(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $profile = $meta['runtime_profile'] ?? null;

        if (is_string($profile) && $profile !== '') {
            return $profile;
        }

        if ($this->server?->isDigitalOceanFunctionsHost()) {
            return 'digitalocean_functions_web';
        }

        if ($this->server?->isAwsLambdaHost()) {
            return 'aws_lambda_bref_web';
        }

        if ($this->server?->isDockerHost()) {
            return 'docker_web';
        }

        if ($this->server?->isKubernetesCluster()) {
            return 'kubernetes_web';
        }

        return 'vm_web';
    }

    public function runtimeProfileLabel(): string
    {
        return match ($this->runtimeProfile()) {
            'docker_web' => __('Docker'),
            'kubernetes_web' => __('Kubernetes'),
            'digitalocean_functions_web' => __('DigitalOcean Functions'),
            'aws_lambda_bref_web' => __('AWS Lambda'),
            'vm_web' => __('BYO VM'),
            default => (string) str($this->runtimeProfile())->replace('_', ' ')->title(),
        };
    }

    public function runtimeExecutionModeLabel(): string
    {
        return match ($this->runtimeTargetMode()) {
            'docker' => __('Container'),
            'kubernetes' => __('Kubernetes'),
            'serverless' => __('Serverless'),
            default => __('VM'),
        };
    }

    /**
     * App/stack detection from persisted meta. Priority matches
     * {@see DeploymentSecretInventory::detectedFramework}:
     * Docker → Kubernetes → serverless → VM (composer.json on deploy).
     *
     * @return array{
     *     source: 'docker'|'kubernetes'|'serverless'|'vm',
     *     framework: string,
     *     language: string,
     *     confidence?: string,
     *     warnings?: list<string>,
     *     detected_files?: list<string>,
     *     laravel_octane?: bool,
     *     laravel_horizon?: bool,
     *     laravel_pulse?: bool,
     *     laravel_reverb?: bool
     * }|null
     */
    public function resolvedRuntimeAppDetection(): ?array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        $candidates = [
            ['source' => 'docker', 'blob' => data_get($meta, 'docker_runtime.detected')],
            ['source' => 'kubernetes', 'blob' => data_get($meta, 'kubernetes_runtime.detected')],
            ['source' => 'serverless', 'blob' => data_get($meta, 'serverless.detected_runtime') ?: data_get($meta, 'serverless.detected')],
            ['source' => 'vm', 'blob' => data_get($meta, 'vm_runtime.detected')],
        ];

        foreach ($candidates as $candidate) {
            $blob = $candidate['blob'];
            if (! is_array($blob) || $blob === []) {
                continue;
            }

            if (! $this->runtimeAppDetectionIsMeaningful($blob)) {
                continue;
            }

            $out = [
                'source' => $candidate['source'],
                'framework' => (string) ($blob['framework'] ?? 'unknown'),
                'language' => (string) ($blob['language'] ?? 'unknown'),
            ];

            if (isset($blob['confidence']) && is_string($blob['confidence']) && $blob['confidence'] !== '') {
                $out['confidence'] = $blob['confidence'];
            }

            if (isset($blob['warnings']) && is_array($blob['warnings'])) {
                $warnings = array_values(array_filter($blob['warnings'], static fn ($w) => is_string($w) && $w !== ''));
                if ($warnings !== []) {
                    $out['warnings'] = $warnings;
                }
            }

            if (isset($blob['detected_files']) && is_array($blob['detected_files'])) {
                $files = array_values(array_filter($blob['detected_files'], static fn ($f) => is_string($f) && $f !== ''));
                if ($files !== []) {
                    $out['detected_files'] = $files;
                }
            }

            foreach (['laravel_octane', 'laravel_horizon', 'laravel_pulse', 'laravel_reverb'] as $laravelPkgKey) {
                if (! empty($blob[$laravelPkgKey])) {
                    $out[$laravelPkgKey] = true;
                }
            }

            return $out;
        }

        return null;
    }

    public function isLaravelFrameworkDetected(): bool
    {
        return strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'laravel';
    }

    public function isRailsFrameworkDetected(): bool
    {
        return strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'rails';
    }

    /**
     * True when the site's detected runtime app is WordPress (per
     * {@see PhpRuntimeDetector}),
     * OR when the site was scaffolded with the WordPress framework
     * pipeline (Q14 — gates the WordPress Settings section).
     */
    public function isWordPressDetected(): bool
    {
        if (strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'wordpress') {
            return true;
        }

        $scaffoldFramework = $this->meta['scaffold']['framework'] ?? null;

        return is_string($scaffoldFramework) && strtolower($scaffoldFramework) === 'wordpress';
    }

    /**
     * @param  array<string, mixed>  $blob
     */
    private function runtimeAppDetectionIsMeaningful(array $blob): bool
    {
        $fw = strtolower(trim((string) ($blob['framework'] ?? '')));
        $lang = strtolower(trim((string) ($blob['language'] ?? '')));

        if ($fw !== '' && $fw !== 'unknown') {
            return true;
        }

        return $lang !== '' && $lang !== 'unknown';
    }

    public function usesFunctionsRuntime(): bool
    {
        return in_array($this->runtimeProfile(), [
            'digitalocean_functions_web',
            'aws_lambda_bref_web',
        ], true);
    }

    public function usesAwsLambdaRuntime(): bool
    {
        return $this->runtimeProfile() === 'aws_lambda_bref_web';
    }

    public function usesDockerRuntime(): bool
    {
        return $this->runtimeProfile() === 'docker_web';
    }

    /**
     * Docker workload on a BYO VM: compose deploy + host port, routed via the
     * server's normal webserver (Caddy/Nginx) to {@see internal_port}.
     */
    public function usesVmDockerRuntime(): bool
    {
        if (! $this->usesDockerRuntime()) {
            return false;
        }

        if ($this->server?->isDockerHost() || $this->usesLocalDockerHostRuntime()) {
            return false;
        }

        return $this->runtimeTargetFamily() === 'byo_vm_docker'
            || data_get($this->meta, 'runtime_target.vm_docker') === true;
    }

    public function usesKubernetesRuntime(): bool
    {
        return $this->runtimeProfile() === 'kubernetes_web';
    }

    /**
     * Whether this site can use the site-scoped systemd Services workspace
     * (dply-site-{id}[-{name}].service). PHP/static and container/serverless
     * workloads use FPM, nginx, or Supervisor (Daemons) instead.
     */
    public static function supportsSystemdServices(Site $site, Server $server): bool
    {
        if (! $server->hostCapabilities()->supportsSsh()) {
            return false;
        }

        if ($site->usesFunctionsRuntime()
            || $site->usesDockerRuntime()
            || $site->usesKubernetesRuntime()) {
            return false;
        }

        return ! in_array((string) ($site->runtime ?? ''), ['php', 'static'], true);
    }

    public function usesContainerRuntime(): bool
    {
        return $this->type === SiteType::Container
            || in_array($this->container_backend, [
                'digitalocean_app_platform',
                'aws_app_runner',
                'dply_cloud',
            ], true);
    }

    public function usesEdgeRuntime(): bool
    {
        return is_string($this->edge_backend) && $this->edge_backend !== '';
    }

    /**
     * PHP / Laravel / Symfony rollout fields (Octane, PHP-FPM, scheduler, etc.).
     * Matches {@see Settings::shouldShowRuntimePhpRolloutFields()}.
     */
    public function shouldShowPhpOctaneRolloutSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $this->type === SiteType::Php
            || in_array($fw, ['laravel', 'php_generic', 'symfony'], true);
    }

    /**
     * Heading for the Runtime "PHP process" block — only includes "Laravel" when Laravel is the detected framework.
     */
    public function runtimePhpProcessSectionTitle(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return match ($fw) {
            'laravel' => __('PHP process & Laravel'),
            'symfony' => __('PHP process & Symfony'),
            'wordpress' => __('PHP process'),
            'php_generic' => __('PHP process'),
            '' => __('PHP process'),
            default => __('PHP process (:stack)', ['stack' => str($fw)->replace('_', ' ')->title()]),
        };
    }

    /**
     * Label for the per-minute cron / scheduler checkbox (word "Laravel" only when detection says Laravel).
     */
    public function runtimeSchedulerCheckboxLabel(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? __('Laravel scheduler (cron)')
            : __('Per-minute cron task');
    }

    /**
     * Helper text when Laravel is not the detected framework but the cron option is still shown (PHP site).
     */
    public function runtimeSchedulerCheckboxHelp(): ?string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? null
            : __('Adds `php artisan schedule:run` each minute. Enable only for Laravel apps that use the scheduler; leave off for Symfony, WordPress, and other stacks.');
    }

    /**
     * Full single-line label for the scheduler checkbox on Deploy → Rollout (includes schedule:run hint for Laravel).
     */
    public function runtimeSchedulerRolloutFormLabel(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? __('Laravel scheduler (schedule:run every minute via server crontab)')
            : __('Per-minute cron task (via server crontab)');
    }

    /**
     * Whether one-shot Laravel SSH setup from Site settings is allowed (BYO VM, SSH ready, Laravel detected).
     */
    public function canRunLaravelSshSetupActions(): bool
    {
        $this->loadMissing('server');
        $server = $this->server;
        if ($server === null || ! $server->isVmHost() || ! $server->isReady()) {
            return false;
        }

        if (trim((string) $server->ssh_private_key) === '') {
            return false;
        }

        if ($server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return false;
        }

        if ($this->type !== SiteType::Php) {
            return false;
        }

        if (trim($this->effectiveEnvDirectory()) === '') {
            return false;
        }

        return true;
    }

    /**
     * Rails-specific fields (e.g. RAILS_ENV in meta).
     * Matches {@see Settings::shouldShowRailsRuntimeFields()}.
     */
    public function shouldShowRailsRuntimeSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $fw === 'rails';
    }

    public function octaneServer(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
        $s = strtolower((string) ($lo['server'] ?? 'swoole'));

        return in_array($s, self::OCTANE_SERVERS, true) ? $s : 'swoole';
    }

    /**
     * @return list<string>
     */
    public function detectedLaravelPackageKeys(): array
    {
        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return [];
        }

        $keys = [];
        foreach (LaravelComposerPackageDetector::PACKAGE_KEYS as $short => $_) {
            $blobKey = 'laravel_'.$short;
            if (($resolved[$blobKey] ?? false) === true) {
                $keys[] = $short;
            }
        }

        return $keys;
    }

    public function resolvedLaravelPackageFlag(string $short): bool
    {
        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return false;
        }

        if (! array_key_exists($short, LaravelComposerPackageDetector::PACKAGE_KEYS)) {
            return false;
        }

        $blobKey = 'laravel_'.$short;

        return ($resolved[$blobKey] ?? false) === true;
    }

    /**
     * Octane settings UI when repository inspection found Laravel and a laravel/octane Composer dependency.
     */
    public function shouldShowOctaneRuntimeUi(): bool
    {
        return $this->resolvedLaravelPackageFlag('octane');
    }

    /**
     * Local port for Reverb WebSocket server (Supervisor / reverse proxy); stored in meta.laravel_reverb.port.
     */
    public function reverbLocalPort(): int
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = $r['port'] ?? 8080;

        return is_numeric($p) ? max(1, min(65535, (int) $p)) : 8080;
    }

    public function shouldShowLaravelReverbRuntimeUi(): bool
    {
        return $this->resolvedLaravelPackageFlag('reverb');
    }

    /**
     * Include Nginx/Caddy/Apache Reverb WebSocket proxy when Reverb is detected or port was saved in meta.
     */
    public function shouldProxyReverbInWebserver(): bool
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $hasSavedPort = is_array($meta['laravel_reverb'] ?? null)
            && array_key_exists('port', $meta['laravel_reverb']);

        return $this->resolvedLaravelPackageFlag('reverb') || $hasSavedPort;
    }

    /**
     * URL path prefix for Laravel Echo + Reverb (default /app).
     */
    public function reverbWebSocketPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = trim((string) ($r['ws_path'] ?? '/app'));
        if ($p === '' || $p[0] !== '/') {
            return '/app';
        }
        if (! preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p)) {
            return '/app';
        }

        return rtrim($p, '/') === '' ? '/app' : rtrim($p, '/');
    }

    public function horizonDashboardPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $h = is_array($meta['laravel_horizon'] ?? null) ? $meta['laravel_horizon'] : [];
        $p = trim((string) ($h['path'] ?? '/horizon'));
        if ($p === '' || $p[0] !== '/') {
            return '/horizon';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/horizon' : '/horizon';
    }

    public function pulseDashboardPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $h = is_array($meta['laravel_pulse'] ?? null) ? $meta['laravel_pulse'] : [];
        $p = trim((string) ($h['path'] ?? '/pulse'));
        if ($p === '' || $p[0] !== '/') {
            return '/pulse';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/pulse' : '/pulse';
    }

    /**
     * Suggested Supervisor command for Reverb; optional port override for form preview before save.
     */
    public function reverbSupervisorCommandLine(?int $portOverride = null): string
    {
        $p = $portOverride ?? $this->reverbLocalPort();

        return sprintf('php artisan reverb:start --host=0.0.0.0 --port=%d', max(1, min(65535, $p)));
    }

    /**
     * Supervisor command line for Octane (run with `directory` = deploy root, e.g. current release).
     */
    public function octaneSupervisorCommand(): string
    {
        $port = $this->octane_port;
        if ($port === null || $port < 1) {
            $port = 8000;
        }

        return sprintf(
            'php artisan octane:start --server=%s --host=127.0.0.1 --port=%d',
            $this->octaneServer(),
            (int) $port
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeTarget(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $target = $meta['runtime_target'] ?? null;

        if (is_array($target) && ($target['family'] ?? null)) {
            return $target;
        }

        return [
            'family' => $this->runtimeTargetFamily(),
            'platform' => $this->runtimeTargetPlatform(),
            'mode' => $this->runtimeTargetMode(),
            'provider' => $this->runtimeTargetProvider(),
            'status' => null,
            'logs' => [],
        ];
    }

    public function runtimeTargetFamily(): string
    {
        $target = is_array($this->meta['runtime_target'] ?? null) ? $this->meta['runtime_target'] : [];
        $family = $target['family'] ?? null;
        if (is_string($family) && $family !== '') {
            return $family;
        }

        if ($this->usesDockerRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_docker',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_docker',
                $this->server?->provider?->value === 'aws' => 'aws_docker',
                default => 'docker',
            };
        }

        if ($this->usesKubernetesRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_kubernetes',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_kubernetes',
                $this->server?->provider?->value === 'aws' => 'aws_kubernetes',
                default => 'kubernetes',
            };
        }

        if ($this->usesAwsLambdaRuntime()) {
            return 'aws_lambda';
        }

        if ($this->usesFunctionsRuntime()) {
            return 'digitalocean_functions';
        }

        return 'byo_vm';
    }

    public function runtimeTargetPlatform(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker', 'local_orbstack_kubernetes' => 'local',
            'digitalocean_docker', 'digitalocean_kubernetes', 'digitalocean_functions' => 'digitalocean',
            'aws_docker', 'aws_kubernetes', 'aws_lambda' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetProvider(): string
    {
        return match ($this->runtimeTargetPlatform()) {
            'local' => 'orbstack',
            'digitalocean' => 'digitalocean',
            'aws' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetMode(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_kubernetes', 'digitalocean_kubernetes', 'aws_kubernetes', 'kubernetes' => 'kubernetes',
            'local_orbstack_docker', 'digitalocean_docker', 'aws_docker', 'docker', 'byo_vm_docker' => 'docker',
            'digitalocean_functions', 'aws_lambda' => 'serverless',
            default => 'vm',
        };
    }

    public function usesLocalDockerHostRuntime(): bool
    {
        return in_array($this->runtimeTargetFamily(), [
            'local_orbstack_docker',
            'local_orbstack_kubernetes',
        ], true);
    }

    public function runtimeTargetLabel(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker' => 'Local Docker',
            'local_orbstack_kubernetes' => 'Local Kubernetes',
            'digitalocean_docker' => 'DigitalOcean Docker',
            'digitalocean_kubernetes' => 'DigitalOcean Kubernetes',
            'aws_docker' => 'AWS Docker',
            'aws_kubernetes' => 'AWS Kubernetes',
            'digitalocean_functions' => 'DigitalOcean Functions',
            'aws_lambda' => 'AWS Lambda',
            default => 'BYO runtime',
        };
    }

    public function runtimeRepositorySubdirectory(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $subdirectory = data_get($meta, 'runtime_target.repository_subdirectory');

        if (! is_string($subdirectory) || trim($subdirectory) === '') {
            $subdirectory = $this->usesKubernetesRuntime()
                ? data_get($meta, 'kubernetes_runtime.repository_subdirectory')
                : data_get($meta, 'docker_runtime.repository_subdirectory');
        }

        return is_string($subdirectory) ? trim($subdirectory, '/') : '';
    }
}
