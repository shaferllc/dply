<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\ProviderCredential;
use App\Models\Site;

/**
 * Common interface that both DigitalOcean App Platform and AWS
 * App Runner adapters implement. The dply cloud layer (anything
 * in App\Services\Cloud) talks to backends through this and never
 * imports the underlying SDK directly — that lets us add more
 * backends (Cloud Run, Render, fly machines proper, custom
 * docker-compose hosts) without touching the rest of the system.
 */
interface CloudBackend
{
    /**
     * Provider key as stored on ProviderCredential.provider —
     * one of: 'digitalocean_app_platform', 'aws_app_runner'.
     */
    public function providerKey(): string;

    /**
     * Provision a new container app for the given site. Returns
     * an opaque backend identifier (DO app_id, App Runner ARN)
     * and the public URL the backend has assigned the app, if
     * known synchronously. The URL may be null on first call —
     * callers should poll inspect() until it appears.
     *
     * @return array{backend_id: string, live_url: ?string}
     */
    public function provision(Site $site, ProviderCredential $credential): array;

    /**
     * Vercel-shape source-mode provision: the backend pulls a
     * GitHub repo + branch and handles build itself (Dockerfile
     * when present, buildpack otherwise). With deploy_on_push
     * enabled at the backend, every push to that branch triggers
     * an auto-redeploy without dply needing to listen for webhooks.
     *
     * Source spec is read off the Site's meta.container.source —
     * { repo, branch, dockerfile_path?, deploy_on_push }.
     *
     * @return array{backend_id: string, live_url: ?string}
     */
    public function provisionFromSource(Site $site, ProviderCredential $credential): array;

    /**
     * Trigger a redeploy of the existing app — backend pulls
     * the latest image tag and rolls a new revision. No-op when
     * the site has no backend_id yet.
     *
     * @return array{deployment_id: ?string}
     */
    public function redeploy(Site $site, ProviderCredential $credential): array;

    /**
     * Update the deployed image tag (and env vars). Used when the
     * operator changes the site's container_image and wants the
     * change to take effect — DO uses updateApp+deployApp;
     * App Runner uses updateImage.
     */
    public function updateImage(Site $site, ProviderCredential $credential, string $image): void;

    /**
     * Push the Site's current env vars (parsed from env_file_content
     * via Site::siteEnvVars()) into the backend's deployment spec,
     * keeping the image / source spec the same. Used when the operator
     * edits env vars on the dashboard. Idempotent — returns without
     * error when there's no backend deployment yet (the values land
     * via the next provision instead).
     */
    public function updateEnvVars(Site $site, ProviderCredential $credential): void;

    /**
     * Tear down the backend resource. Should be idempotent —
     * subsequent calls on a missing resource MUST NOT raise.
     */
    public function teardown(Site $site, ProviderCredential $credential): void;

    /**
     * Read the current status from the backend for status polling.
     *
     * @return array{phase: string, live_url: ?string, raw: array<string, mixed>}
     */
    public function inspect(Site $site, ProviderCredential $credential): array;

    /**
     * Region slugs the backend supports. Used by the create UI
     * to render the region picker.
     *
     * @return list<array{slug: string, label: string}>
     */
    public function regions(): array;

    /**
     * Attach a custom hostname to the deployed app. Returns any
     * DNS validation records the operator must publish at their
     * registrar (App Runner returns CNAME validation records;
     * DO returns an empty array — DO uses ALIAS / A pointing at
     * the default ingress and verifies live).
     *
     * @return list<array{name: string, type: string, value: string, status: string}>
     */
    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array;

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void;

    /**
     * Fetch logs for the latest deployment. Returns either:
     *   - 'content': inline log text the operator can read directly
     *   - 'url': a presigned URL the operator can curl / open
     *   - 'message': a backend-specific note (e.g. "logs are in
     *     CloudWatch under stream X") when the backend can't return
     *     logs synchronously over its standard API.
     *
     * @return array{content: ?string, url: ?string, message: ?string}
     */
    public function latestDeploymentLogs(Site $site, ProviderCredential $credential): array;

    /**
     * Recent deployments for the site, newest-first. Each entry is
     * a normalized shape so the CLI / dashboard can render across
     * backends without backend-specific casework.
     *
     * @return list<array{id: string, phase: string, started_at: ?string, finished_at: ?string, cause: ?string}>
     */
    public function recentDeployments(Site $site, ProviderCredential $credential, int $limit = 10): array;

    /**
     * Fetch CPU / memory / restart (or request) metric series for the
     * site over the requested window. The window is a short code —
     * '1h', '6h', or '24h' — and the backend maps it to a concrete
     * start/end timestamp pair.
     *
     * Returns a normalized, backend-agnostic structure:
     *   - 'window': the echoed window code
     *   - 'series': map of metric name → list of {t: unix-int, v: float}
     *       points. DigitalOcean / Fake return cpu, memory, restarts.
     *   - 'available': false when the backend cannot return metrics
     *       (App Runner — see the CloudWatch fallback).
     *   - 'note': optional human-readable note (e.g. CloudWatch hint).
     *   - 'url': optional deep link the operator can open instead.
     *
     * Implementations MUST degrade to available:false rather than
     * throw when the provider API is unreachable or returns an
     * unexpected shape — the dashboard renders the unavailable state.
     *
     * @return array{window: string, series: array<string, list<array{t: int, v: float}>>, available: bool, note?: string, url?: string}
     */
    public function metrics(Site $site, ProviderCredential $credential, string $window): array;

    /**
     * Fetch RUN (runtime) logs for the site — the live application
     * stdout/stderr, not BUILD or DEPLOY logs. Returns:
     *   - 'lines': list of log line strings (most-recent-last), capped
     *       at roughly $lines entries.
     *   - 'available': false when the backend cannot return runtime
     *       logs synchronously (App Runner streams to CloudWatch).
     *   - 'url': optional link — DO returns a presigned archive URL;
     *       App Runner returns a CloudWatch console deep link.
     *   - 'note': optional human-readable note.
     *
     * Implementations MUST degrade gracefully rather than throw.
     *
     * @return array{lines: list<string>, available: bool, url?: string, note?: string}
     */
    public function runtimeLogs(Site $site, ProviderCredential $credential, int $lines = 200, string $component = 'web'): array;

    /**
     * Whether the backend can run background processes (queue workers,
     * the Laravel scheduler) alongside the web service.
     *
     * DigitalOcean App Platform supports `workers` components in the
     * app spec, so it returns true. AWS App Runner is HTTP-request-
     * driven only — it has no concept of a long-running non-HTTP
     * process — so it returns false and worker creation is rejected
     * for App Runner sites.
     */
    public function supportsWorkers(): bool;

    /**
     * Whether the backend can run one-shot deploy tasks tied to the
     * deploy lifecycle (migrations on PRE_DEPLOY, cache warm on
     * POST_DEPLOY, etc). DigitalOcean App Platform supports `jobs`
     * components in the spec; AWS App Runner has no equivalent.
     */
    public function supportsDeployTasks(): bool;

    /**
     * Whether the backend can route DEPLOYMENT_FAILED / CPU / MEM /
     * RESTART alerts to dply's destinations (Slack + emails).
     * DigitalOcean App Platform supports first-class `alerts` blocks
     * in the spec and a per-alert destinations endpoint; AWS App
     * Runner uses CloudWatch which we don't bridge yet.
     */
    public function supportsAlerts(): bool;

    /**
     * Cancel the site's currently-in-progress deploy if any. Returns
     * true when a deploy was actually canceled, false when there was
     * nothing in progress to cancel.
     */
    public function cancelInProgressDeployment(Site $site, ProviderCredential $credential): bool;

    /**
     * Whether the backend can apply dply's autoscaling rules (CPU-target
     * based min/max instance counts) and HTTP health-check configuration.
     *
     * DigitalOcean App Platform supports both first-class in its app
     * spec (`autoscaling` + `health_check` blocks), so it returns true.
     * AWS App Runner has native AutoScalingConfiguration + a
     * HealthCheckConfiguration on the service, so it returns true too —
     * see syncScaling() for the degradation note. The Fake backend
     * mirrors DO and returns true so dev installs / tests can exercise
     * the flow end to end.
     */
    public function supportsAutoscaling(): bool;

    /**
     * Push the site's autoscaling + health-check configuration
     * (meta.container.autoscaling / meta.container.health_check) into
     * the backend's deployment spec and roll a new deployment so the
     * change takes effect.
     *
     * Rebuilt from the site's current config each call — idempotent.
     * No-op when the site has no backend deployment yet (the config
     * lands via the next provision instead).
     *
     * Backends that cannot apply autoscaling MUST throw a clear
     * exception — see supportsAutoscaling().
     */
    public function syncScaling(Site $site, ProviderCredential $credential): void;

    /**
     * Push the site's CloudWorker rows into the backend's deployment
     * spec — one background component per worker, built from the same
     * source/image as the web service — and roll a new deployment so
     * the change takes effect. Used by worker create / scale / delete.
     *
     * Idempotent: rebuilding the spec from the current CloudWorker rows
     * each call means a delete simply omits the component. No-op when
     * the site has no backend deployment yet (the workers land via the
     * next provision instead).
     *
     * Backends that do not support workers MUST throw a clear
     * exception — see supportsWorkers().
     */
    public function syncWorkers(Site $site, ProviderCredential $credential): void;
}
