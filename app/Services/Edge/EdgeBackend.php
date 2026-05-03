<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\ProviderCredential;
use App\Models\Site;

/**
 * Common interface that both DigitalOcean App Platform and AWS
 * App Runner adapters implement. The dply edge layer (anything
 * in App\Services\Edge) talks to backends through this and never
 * imports the underlying SDK directly — that lets us add more
 * backends (Cloud Run, Render, fly machines proper, custom
 * docker-compose hosts) without touching the rest of the system.
 */
interface EdgeBackend
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
}
