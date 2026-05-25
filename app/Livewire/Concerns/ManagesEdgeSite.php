<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Actions\Edge\DeployEdgeCommit;
use App\Actions\Edge\RedeployEdgeSite;
use App\Actions\Edge\RollbackEdgeDeployment;
use App\Jobs\TeardownEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgeCachePurger;
use App\Services\Edge\EdgeCustomDomainProvisioner;
use App\Services\Edge\EdgeGithubWebhookProvisioner;
use App\Services\Edge\EdgeHostMapPublisher;
use App\Services\Edge\EdgeRouter;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SiteGitCommitsFetcher;
use App\Services\SourceControl\SourceControlRepositoryReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Edge dashboard actions for Sites\Settings — redeploy, rollback,
 * preview teardown, custom domains. Mirrors {@see ManagesContainerSite}.
 */
trait ManagesEdgeSite
{
    public string $edge_domain_input = '';

    public string $edge_webhook_account_id = '';

    public string $edge_deploy_commit_sha = '';

    /**
     * Branch context captured at picker time so the resulting EdgeDeployment
     * records the branch the user actually browsed (not the site's stored
     * default). Cleared on manual SHA edits since the operator could be
     * typing any branch's SHA.
     */
    public ?string $edge_deploy_commit_branch = null;

    /**
     * Ref kind picked from the browser ('branch', 'tag', or 'commit') so the
     * preview row can render a tag-vs-branch distinction. Without this, tag
     * picks lose context and surface as the site's default branch ("main")
     * because tags aren't branches and edge_deploy_commit_branch alone is
     * ambiguous. Null when typed manually or after a manual SHA edit.
     */
    public ?string $edge_deploy_commit_ref_kind = null;

    /**
     * Tracks an in-flight ad-hoc preview so the Create button stays in its
     * loading state across the full job lifecycle (queue → build → publish
     * → KV propagation), not just the few ms the Livewire action runs.
     * Cleared when the preview reaches a terminal status (active or failed).
     */
    public ?string $edge_adhoc_preview_pending_site_id = null;

    public bool $edge_deploy_ref_picker_open = false;

    public string $edge_deploy_ref_tab = 'commits';

    public string $edge_deploy_ref_search = '';

    public string $edge_deploy_ref_branch = '';

    /** @var list<array<string, mixed>> */
    public array $edge_deploy_ref_results = [];

    public ?string $edge_deploy_ref_error = null;

    /**
     * When set, the picker shows the shared "Connect a provider" CTA instead
     * of the raw error string — value is the provider id (github / gitlab /
     * bitbucket) so the copy can name it.
     */
    public ?string $edge_deploy_ref_needs_provider = null;

    public int $edge_releases_to_keep = 10;

    public string $edge_build_command = '';

    public string $edge_output_dir = 'dist';

    public bool $edge_spa_fallback = true;

    public bool $edge_deploy_on_push = true;

    public string $edge_origin_url = '';

    /** Multi-line — one route pattern per line. Persisted as a list<string>. */
    public string $edge_origin_routes = '';

    /** Path the origin healthcheck hits before flipping Edge to LIVE. */
    public string $edge_origin_healthcheck_path = '/';

    /**
     * Optional per-site failover HTML the Worker returns when the origin
     * proxy fails. Empty string falls back to the built-in dply page.
     * Capped at 32 KB so it stays well under the KV value limit.
     */
    public string $edge_origin_failover_html = '';

    public function mountEdgeWebhookAccount(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }

        $edge = $this->site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];
        $origin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];

        $this->edge_build_command = (string) ($build['command'] ?? 'npm ci && npm run build');
        $this->edge_output_dir = (string) ($build['output_dir'] ?? 'dist');
        $this->edge_spa_fallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));
        $this->edge_deploy_on_push = (bool) ($source['deploy_on_push'] ?? true);

        $this->edge_origin_url = (string) ($origin['url'] ?? '');
        $originRoutes = is_array($origin['routes'] ?? null) ? $origin['routes'] : [];
        $this->edge_origin_routes = implode("\n", array_values(array_filter(array_map(
            fn ($route) => is_string($route) ? $route : null,
            $originRoutes,
        ))));
        $this->edge_origin_healthcheck_path = trim((string) ($origin['healthcheck_path'] ?? '/')) ?: '/';
        $this->edge_origin_failover_html = is_string($origin['failover_html'] ?? null) ? (string) $origin['failover_html'] : '';

        $images = is_array($edge['images'] ?? null) ? $edge['images'] : [];
        $this->edge_image_optimization_enabled = is_string($images['signing_secret'] ?? null) && $images['signing_secret'] !== '';
        $imageHosts = is_array($images['allowed_hosts'] ?? null) ? $images['allowed_hosts'] : [];
        $this->edge_image_allowed_hosts = implode("\n", array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? $host : null,
            $imageHosts,
        ))));

        $widget = is_array($edge['comment_widget'] ?? null) ? $edge['comment_widget'] : [];
        $this->edge_comment_widget_enabled = (bool) ($widget['enabled'] ?? false);

        $webhook = is_array($edge['webhook'] ?? null) ? $edge['webhook'] : null;
        $accountId = is_array($webhook) ? (string) ($webhook['account_id'] ?? '') : '';
        if ($accountId !== '') {
            $this->edge_webhook_account_id = $accountId;
        }

        $configured = (int) ($this->site->releases_to_keep ?? 0);
        $this->edge_releases_to_keep = $configured > 0
            ? $configured
            : (int) config('edge.retention.default_keep', 10);
    }

    public function saveEdgeReleasesToKeep(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $value = (int) $this->edge_releases_to_keep;
        if ($value < 1 || $value > 50) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Releases to keep must be between 1 and 50.'));
            }

            return;
        }

        $this->site->update(['releases_to_keep' => $value]);
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Retention updated.'));
        }
    }

    public function saveEdgeBuildSettings(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'edge_build_command' => ['required', 'string', 'max:500'],
            'edge_output_dir' => ['required', 'string', 'max:200'],
            'edge_spa_fallback' => ['boolean'],
            'edge_deploy_on_push' => ['boolean'],
        ]);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];

        $previousSpaFallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));

        $build['command'] = trim((string) $validated['edge_build_command']);
        $build['output_dir'] = trim((string) $validated['edge_output_dir']);
        $source['deploy_on_push'] = (bool) $validated['edge_deploy_on_push'];
        $routing['spa_fallback'] = (bool) $validated['edge_spa_fallback'];

        $site->mergeEdgeMeta([
            'build' => $build,
            'source' => $source,
            'routing' => $routing,
        ]);
        $site->save();
        $this->site->refresh();

        if ($previousSpaFallback !== (bool) $validated['edge_spa_fallback']) {
            $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
            if (is_string($activeId) && $activeId !== '') {
                $deployment = EdgeDeployment::query()->find($activeId);
                if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                    app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
                }
            }
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Build settings saved. Changes apply on the next deploy.'));
        }
    }

    /**
     * Edit the hybrid SSR origin URL and proxy route patterns. Only valid
     * for sites already in hybrid mode — static→hybrid conversion is A6.
     *
     * Saves into meta.edge.origin (preserving `managed` / `cloud_site_id`),
     * audits the change, and re-publishes the KV host map so the Worker
     * picks up the new origin without waiting for the next deploy.
     */
    public function saveEdgeHybridOrigin(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') !== 'hybrid') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Origin settings only apply to hybrid Edge sites.'));
            }

            return;
        }

        $this->validate([
            'edge_origin_url' => ['required', 'string', 'max:500', 'url:http,https'],
            'edge_origin_routes' => ['required', 'string', 'max:2000'],
            'edge_origin_healthcheck_path' => ['required', 'string', 'max:200', 'regex:#^/[^\s]*$#'],
            'edge_origin_failover_html' => ['nullable', 'string', 'max:32768'],
        ], [
            'edge_origin_url.url' => __('Origin URL must be a valid http(s) URL.'),
            'edge_origin_healthcheck_path.regex' => __('Healthcheck path must start with / and contain no spaces.'),
            'edge_origin_failover_html.max' => __('Failover HTML must be 32 KB or smaller.'),
        ]);

        $routes = array_values(array_filter(array_map(
            fn (string $line): string => trim($line),
            preg_split('/\R/', $this->edge_origin_routes) ?: [],
        )));
        if ($routes === []) {
            $this->addError('edge_origin_routes', __('Add at least one proxy route (e.g. /api/*).'));

            return;
        }
        foreach ($routes as $route) {
            if ($route[0] !== '/' || preg_match('/\s/', $route) === 1) {
                $this->addError(
                    'edge_origin_routes',
                    __('Route ":route" is invalid — must start with / and contain no spaces.', ['route' => $route]),
                );

                return;
            }
        }

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousOrigin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];

        $failoverHtml = trim($this->edge_origin_failover_html);
        $newOrigin = [
            'url' => trim($this->edge_origin_url),
            'cloud_site_id' => $previousOrigin['cloud_site_id'] ?? null,
            'managed' => (bool) ($previousOrigin['managed'] ?? false),
            'routes' => $routes,
            'healthcheck_path' => trim($this->edge_origin_healthcheck_path) ?: '/',
            'failover_html' => $failoverHtml !== '' ? $failoverHtml : null,
            // Auto-generate the Worker→origin auth secret on first save so
            // the origin can reject anything that bypasses the Edge by
            // resolving the origin URL directly. Operator can rotate it
            // later via {@see rotateEdgeHybridOriginSecret()}.
            'auth_secret' => is_string($previousOrigin['auth_secret'] ?? null) && $previousOrigin['auth_secret'] !== ''
                ? $previousOrigin['auth_secret']
                : Str::random(48),
        ];

        if ($newOrigin === $previousOrigin) {
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('Origin settings unchanged.'));
            }

            return;
        }

        $site->mergeEdgeMeta(['origin' => $newOrigin]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.origin.updated', $site, [
                'origin' => $previousOrigin,
            ], [
                'origin' => $newOrigin,
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Origin settings saved. Worker host map updated.'));
        }
    }

    /** One-time origin URL input for converting a static site to hybrid. */
    public string $edge_convert_origin_url = '';

    /** Tag to purge from EDGE_CACHE for hybrid sites. */
    public string $edge_cache_purge_tag = '';

    /** Multi-line — one source hostname per line for the image optimizer allowlist. */
    public string $edge_image_allowed_hosts = '';

    public bool $edge_image_optimization_enabled = false;

    /**
     * Enable + edit the image optimizer for this Edge site. First save
     * generates the signing secret; subsequent saves replace the
     * source-host allowlist. Once enabled, signed URLs in the form
     * https://{hostname}/_dply/image?url=...&w=...&q=...&fmt=...&sig=...
     * resize via Cloudflare Image Resizing.
     */
    public function saveEdgeImageOptimization(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $this->validate([
            'edge_image_allowed_hosts' => ['required_if:edge_image_optimization_enabled,true', 'nullable', 'string', 'max:2000'],
        ]);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousImages = is_array($edge['images'] ?? null) ? $edge['images'] : [];

        if (! $this->edge_image_optimization_enabled) {
            // Disable: drop everything. Worker will return 404 on /_dply/image.
            $site->mergeEdgeMeta(['images' => []]);
            $site->save();
            $this->site->refresh();

            $org = $site->organization;
            if ($org !== null) {
                audit_log($org, auth()->user(), 'site.edge.images.disabled', $site, [
                    'images' => $previousImages,
                ], null);
            }

            $this->republishHostMapForActiveDeployment();
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('Image optimization disabled.'));
            }

            return;
        }

        $hosts = $this->parseEdgeAllowedHosts($this->edge_image_allowed_hosts);
        if ($hosts === []) {
            $this->addError('edge_image_allowed_hosts', __('Add at least one source hostname.'));

            return;
        }
        foreach ($hosts as $host) {
            if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
                $this->addError('edge_image_allowed_hosts', __('Hostname ":h" looks invalid — letters, digits, dot, dash only.', ['h' => $host]));

                return;
            }
        }

        $newImages = [
            'signing_secret' => is_string($previousImages['signing_secret'] ?? null) && $previousImages['signing_secret'] !== ''
                ? $previousImages['signing_secret']
                : Str::random(48),
            'allowed_hosts' => $hosts,
        ];

        $site->mergeEdgeMeta(['images' => $newImages]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.images.saved', $site, [
                'images' => $previousImages,
            ], [
                'images' => $newImages,
            ]);
        }

        $this->republishHostMapForActiveDeployment();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Image optimization settings saved. Worker host map updated.'));
        }
    }

    public function rotateEdgeImageSigningSecret(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousImages = is_array($edge['images'] ?? null) ? $edge['images'] : [];
        if (($previousImages['signing_secret'] ?? '') === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Enable image optimization first.'));
            }

            return;
        }

        $newImages = $previousImages;
        $newImages['signing_secret'] = Str::random(48);

        $site->mergeEdgeMeta(['images' => $newImages]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.images.secret_rotated', $site);
        }

        $this->republishHostMapForActiveDeployment();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Image signing secret rotated. Re-sign any pre-rendered URLs.'));
        }
    }

    public bool $edge_comment_widget_enabled = false;

    /**
     * Toggle the on-page preview comment widget for this site's
     * children. The flag (and its auto-generated widget_token) live on
     * the parent so all PR previews inherit the setting without
     * per-preview toggling. The Worker injects the widget bootstrap
     * script on HTML responses of preview hostnames when enabled.
     */
    public function saveEdgeCommentWidget(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previous = is_array($edge['comment_widget'] ?? null) ? $edge['comment_widget'] : [];

        if (! $this->edge_comment_widget_enabled) {
            $site->mergeEdgeMeta(['comment_widget' => []]);
            $site->save();
            $this->site->refresh();

            $org = $site->organization;
            if ($org !== null) {
                audit_log($org, auth()->user(), 'site.edge.comment_widget.disabled', $site, [
                    'comment_widget' => $previous,
                ], null);
            }

            // Re-publish previews of this site so the Worker stops
            // injecting. The parent itself is usually not a preview, so
            // republishing its own active deployment is a no-op for the
            // widget but harmless.
            $this->republishHostMapForActiveDeployment();

            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('Preview comment widget disabled.'));
            }

            return;
        }

        $newWidget = [
            'enabled' => true,
            'token' => is_string($previous['token'] ?? null) && $previous['token'] !== ''
                ? $previous['token']
                : Str::random(48),
        ];

        $site->mergeEdgeMeta(['comment_widget' => $newWidget]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.comment_widget.enabled', $site, [
                'comment_widget' => $previous,
            ], [
                'comment_widget' => $newWidget,
            ]);
        }

        $this->republishHostMapForActiveDeployment();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Preview comment widget enabled. New previews ship with the widget script injected.'));
        }
    }

    /** @return list<string> */
    private function parseEdgeAllowedHosts(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $line): string => strtolower(trim($line)),
            preg_split('/\R/', $raw) ?: [],
        ), fn (string $line): bool => $line !== '')));
    }

    /**
     * Re-publish the KV host map for whatever deployment is currently
     * LIVE so Worker meta changes (origin, images, cache settings) take
     * effect without waiting for a redeploy. No-op when nothing is live.
     */
    private function republishHostMapForActiveDeployment(): void
    {
        $activeId = $this->site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return;
        }
        $deployment = EdgeDeployment::query()->find($activeId);
        if ($deployment === null || $deployment->status !== EdgeDeployment::STATUS_LIVE) {
            return;
        }
        app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $deployment);
    }

    /**
     * Purge cache entries for a single Cache-Tag value from the Worker
     * EDGE_CACHE namespace. The Worker indexes cached responses by tag
     * when the origin sets `Cache-Tag: foo,bar` or `X-Dply-Cache-Tag: foo,bar`
     * in its cacheable response; this removes the indexed entry (and the
     * removes the indexed entry (and the pointer) via Cloudflare's KV
     * REST API. Audit-logged so operators have a record of invalidations.
     */
    public function purgeEdgeCacheByTag(EdgeCachePurger $purger): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $tag = trim($this->edge_cache_purge_tag);
        if ($tag === '') {
            $this->addError('edge_cache_purge_tag', __('Enter a tag to purge.'));

            return;
        }

        $result = $purger->purgeByTag($this->site->fresh(), $tag);

        $org = $this->site->organization;
        if ($result['ok'] && $org !== null && $result['purged_keys'] !== []) {
            audit_log($org, auth()->user(), 'site.edge.cache.purged_by_tag', $this->site, null, [
                'tag' => $tag,
                'purged_keys' => $result['purged_keys'],
            ]);
        }

        if (method_exists($this, $result['ok'] ? 'toastSuccess' : 'toastError')) {
            $method = $result['ok'] ? 'toastSuccess' : 'toastError';
            $this->{$method}($result['message']);
        }

        if ($result['ok']) {
            $this->edge_cache_purge_tag = '';
        }
    }

    /**
     * Convert a static Edge site to hybrid by pointing it at an existing
     * origin URL. Writes runtime_mode='hybrid', sets meta.edge.origin with
     * the provided URL + sensible defaults (routes = /api/*, /_next/data/*;
     * healthcheck_path = /; auth_secret auto-generated), and re-publishes
     * the KV host map so the Worker picks up the change.
     *
     * TODO: a second flow that *provisions* a fresh Cloud origin instead
     * of accepting an existing URL — would reuse {@see CreateHybridEdgeStack}
     * but rewire it to update an existing Edge site rather than create one.
     */
    public function convertEdgeStaticToHybrid(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') === 'hybrid') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Site is already in hybrid mode.'));
            }

            return;
        }

        $this->validate([
            'edge_convert_origin_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ], [
            'edge_convert_origin_url.url' => __('Origin URL must be a valid http(s) URL.'),
        ]);

        $site = $this->site->fresh();
        $site->mergeEdgeMeta([
            'runtime_mode' => 'hybrid',
            'origin' => [
                'url' => trim($this->edge_convert_origin_url),
                'cloud_site_id' => null,
                'managed' => false,
                'routes' => ['/api/*', '/_next/data/*'],
                'healthcheck_path' => '/',
                'failover_html' => null,
                'auth_secret' => Str::random(48),
            ],
        ]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.converted_to_hybrid', $site, null, [
                'origin_url' => trim($this->edge_convert_origin_url),
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        // Re-sync the trait form state so the now-visible hybrid form
        // shows the new origin without a full page reload.
        $this->edge_convert_origin_url = '';
        $this->mountEdgeWebhookAccount();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Site converted to hybrid. Worker host map updated.'));
        }
    }

    /**
     * Rotate the Worker→origin auth secret. The Worker attaches the
     * current secret as `X-Dply-Origin-Auth` on every proxied request;
     * the origin app should compare against the configured value and
     * reject mismatches. Rotating invalidates anything pinned to the
     * old value until the origin app picks up the new secret + the new
     * KV host map propagates.
     */
    public function rotateEdgeHybridOriginSecret(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') !== 'hybrid') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Origin secret rotation only applies to hybrid Edge sites.'));
            }

            return;
        }

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousOrigin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];
        if (($previousOrigin['url'] ?? '') === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Set the origin URL first.'));
            }

            return;
        }

        $newOrigin = $previousOrigin;
        $newOrigin['auth_secret'] = Str::random(48);

        $site->mergeEdgeMeta(['origin' => $newOrigin]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.origin.secret_rotated', $site);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Origin auth secret rotated. Update your origin app and redeploy if needed.'));
        }
    }

    public function enableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        if ($this->edge_webhook_account_id === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Select a linked GitHub account first.'));
            }

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->edge_webhook_account_id)
            : null;
        if ($account === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('That GitHub account is no longer linked.'));
            }

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError((string) ($result['message'] ?? __('Could not connect GitHub webhook.')));
            }

            return;
        }

        $this->site->refresh();
        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess((string) ($result['message'] ?? __('GitHub webhook connected.')));
        }
    }

    public function disableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $account = null;
        if ($this->edge_webhook_account_id !== '' && auth()->user() !== null) {
            $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->edge_webhook_account_id);
        }

        $provisioner->disable($this->site->fresh(), $account);
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('GitHub webhook disconnected.'));
        }
    }

    public function redeployEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge redeploy queued.'));
        }
    }

    public function deployEdgeCommit(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $sha = trim($this->edge_deploy_commit_sha);
        if ($sha === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Enter a commit SHA to deploy.'));
            }

            return;
        }

        try {
            (new DeployEdgeCommit)->handle($this->site, $sha, $this->edge_deploy_commit_branch);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_deploy_commit_sha = '';
        $this->edge_deploy_commit_branch = null;

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Deploy started for that commit.'));
        }
    }

    public function openEdgeDeployRefPicker(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $source = is_array($this->site->edgeMeta()['source'] ?? null) ? $this->site->edgeMeta()['source'] : [];
        $this->edge_deploy_ref_branch = (string) ($source['branch'] ?? 'main');
        $this->edge_deploy_ref_picker_open = true;
        $this->refreshEdgeDeployRefs();
    }

    public function closeEdgeDeployRefPicker(): void
    {
        $this->edge_deploy_ref_picker_open = false;
    }

    public function setEdgeDeployRefTab(string $tab): void
    {
        if (! in_array($tab, ['commits', 'branches', 'tags'], true)) {
            return;
        }

        $this->edge_deploy_ref_tab = $tab;
        $this->refreshEdgeDeployRefs();
    }

    public function updatedEdgeDeployRefSearch(): void
    {
        if ($this->edge_deploy_ref_picker_open) {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function updatedEdgeDeployRefBranch(): void
    {
        if ($this->edge_deploy_ref_picker_open && $this->edge_deploy_ref_tab === 'commits') {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function selectEdgeDeployRef(string $sha): void
    {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            return;
        }

        $this->edge_deploy_commit_sha = $sha;

        // Capture the picked ref's branch + kind so downstream code can
        // label the row correctly and persist an honest preview_branch.
        // Tags lose their identity if we only carry the branch field
        // (they aren't branches), so kind disambiguates tag-vs-branch.
        $branch = null;
        $kind = null;
        if ($this->edge_deploy_ref_tab === 'commits') {
            $branch = trim($this->edge_deploy_ref_branch) !== ''
                ? trim($this->edge_deploy_ref_branch)
                : null;
            $kind = $branch !== null ? 'commit' : null;
        } elseif ($this->edge_deploy_ref_tab === 'branches') {
            foreach ($this->edge_deploy_ref_results as $ref) {
                if (strtolower(trim((string) ($ref['sha'] ?? ''))) === $sha) {
                    $label = trim((string) ($ref['label'] ?? ''));
                    $branch = $label !== '' ? $label : null;
                    $kind = $branch !== null ? 'branch' : null;
                    break;
                }
            }
        } elseif ($this->edge_deploy_ref_tab === 'tags') {
            foreach ($this->edge_deploy_ref_results as $ref) {
                if (strtolower(trim((string) ($ref['sha'] ?? ''))) === $sha) {
                    $label = trim((string) ($ref['label'] ?? ''));
                    // Stash the tag name in the branch slot so name+row UI
                    // both display the tag instead of falling back to main.
                    $branch = $label !== '' ? $label : null;
                    $kind = $branch !== null ? 'tag' : null;
                    break;
                }
            }
        }

        $this->edge_deploy_commit_branch = $branch;
        $this->edge_deploy_commit_ref_kind = $kind;
        $this->closeEdgeDeployRefPicker();
    }

    public function updatedEdgeDeployCommitSha(): void
    {
        // Operator typed/pasted a SHA — the previously picked branch context
        // no longer applies. DeployEdgeCommit will fall back to the site's
        // stored default branch (same as before this feature existed).
        $this->edge_deploy_commit_branch = null;
        $this->edge_deploy_commit_ref_kind = null;
    }

    /**
     * Create an ad-hoc preview site from the SHA currently staged in the
     * deploy-ref form. Reuses the same picker state as production deploys —
     * operator only ever browses commits in one place.
     *
     * Idempotent: hitting Create twice with the same SHA returns the existing
     * preview rather than building a duplicate. The branch context is required
     * (typed SHAs without a branch are rejected) because the build needs to
     * know which ref to check out.
     */
    public function createAdhocEdgePreview(): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $sha = strtolower(trim($this->edge_deploy_commit_sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Pick a commit (or type a 7–40 char SHA) before creating a preview.'));
            }

            return;
        }

        $branch = $this->edge_deploy_commit_branch !== null
            ? trim($this->edge_deploy_commit_branch)
            : '';
        if ($branch === '') {
            $source = is_array($this->site->edgeMeta()['source'] ?? null)
                ? $this->site->edgeMeta()['source']
                : [];
            $branch = trim((string) ($source['branch'] ?? 'main'));
            if ($branch === '') {
                $branch = 'main';
            }
        }

        try {
            $preview = app(CreateEdgePreviewSite::class)->handleAdhoc(
                $this->site,
                $branch,
                $sha,
                $this->edge_deploy_commit_ref_kind,
            );
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_deploy_commit_sha = '';
        $this->edge_deploy_commit_branch = null;
        $this->edge_deploy_commit_ref_kind = null;
        $this->edge_adhoc_preview_pending_site_id = (string) $preview->id;

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Preview build queued — the URL stays disabled until the worker is live.'));
        }
    }

    /**
     * Polled by the Previews tab while a Create is in flight. Returns true
     * while the tracked preview is still building/publishing OR while the
     * Cloudflare KV negative-lookup window is still closing (a brief grace
     * period after the deployment row flips to live). Clears the pending ID
     * once it's safe to click the URL.
     */
    public function adhocPreviewIsPending(): bool
    {
        if ($this->edge_adhoc_preview_pending_site_id === null) {
            return false;
        }

        $preview = Site::query()->find($this->edge_adhoc_preview_pending_site_id);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            $this->edge_adhoc_preview_pending_site_id = null;

            return false;
        }

        // Failed → stop spinning so the user sees the failure and can retry.
        if ($preview->status === Site::STATUS_EDGE_FAILED) {
            $this->edge_adhoc_preview_pending_site_id = null;
            if (method_exists($this, 'toastError')) {
                $latest = $preview->edgeDeployments()->latest()->first();
                $reason = $latest?->failure_reason ?: __('Preview build failed — see deploy log.');
                $this->toastError($reason);
            }

            return false;
        }

        // Live → still hold the spinner for ~45s after publish to outlive
        // Cloudflare's KV negative-cache window. The deployment.published_at
        // is set by CloudflareEdgeDelivery::publishDeployment right after
        // the host map PUT lands, so it's the right t=0 for propagation.
        if ($preview->status === Site::STATUS_EDGE_ACTIVE) {
            $deployment = $preview->edgeDeployments()->latest()->first();
            $publishedAt = $deployment?->published_at;
            if ($publishedAt === null || $publishedAt->diffInSeconds(now()) >= 45) {
                $this->edge_adhoc_preview_pending_site_id = null;
                if (method_exists($this, 'toastSuccess')) {
                    $this->toastSuccess(__('Preview is live — the URL should respond now.'));
                }

                return false;
            }
        }

        return true;
    }

    private function refreshEdgeDeployRefs(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = __('Sign in to browse repository refs.');
            $this->edge_deploy_ref_needs_provider = null;

            return;
        }

        $search = mb_strtolower(trim($this->edge_deploy_ref_search));

        if ($this->edge_deploy_ref_tab === 'branches') {
            $result = app(SourceControlRepositoryReader::class)->branches($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load branches.'));
                $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_needs_provider = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['branches'] ?? [])
                    ->map(fn (array $branch): array => [
                        'kind' => 'branch',
                        'label' => (string) ($branch['name'] ?? ''),
                        'sha' => (string) ($branch['sha'] ?? ''),
                        'meta' => ($branch['is_default'] ?? false) ? __('Default branch') : null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        if ($this->edge_deploy_ref_tab === 'tags') {
            $result = app(SourceControlRepositoryReader::class)->tags($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load tags.'));
                $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_needs_provider = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['tags'] ?? [])
                    ->map(fn (array $tag): array => [
                        'kind' => 'tag',
                        'label' => (string) ($tag['name'] ?? ''),
                        'sha' => (string) ($tag['sha'] ?? ''),
                        'meta' => null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        $branch = trim($this->edge_deploy_ref_branch) !== '' ? trim($this->edge_deploy_ref_branch) : null;
        $result = app(SiteGitCommitsFetcher::class)->fetch($this->site, $user, 40, $branch);
        if (! ($result['ok'] ?? false)) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load commits.'));
            $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

            return;
        }

        $this->edge_deploy_ref_error = null;
        $this->edge_deploy_ref_needs_provider = null;
        $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
            collect($result['commits'] ?? [])
                ->map(fn (array $commit): array => [
                    'kind' => 'commit',
                    'label' => (string) ($commit['short_sha'] ?? substr((string) ($commit['sha'] ?? ''), 0, 7)),
                    'sha' => (string) ($commit['sha'] ?? ''),
                    'meta' => Str::limit((string) ($commit['message'] ?? ''), 72),
                ])
                ->all(),
            $search,
            ['label', 'sha', 'meta'],
        );
    }

    /**
     * Provider id (github / gitlab / bitbucket) the current user needs to
     * link before the deploy-ref form can do anything useful, or null when
     * they already have an identity (or the site has no remote we can
     * browse). The deploys table uses this to hide the whole "Deploy ref"
     * form when there's nothing the user could actually do with it.
     */
    public function edgeDeployRefMissingProvider(): ?string
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $provider = app(SourceControlRepositoryReader::class)->providerForSite($this->site);
        if ($provider === null || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return null;
        }

        return app(GitIdentityResolver::class)->forUserProvider($user, $provider) === null
            ? $provider
            : null;
    }

    #[On('source-control-linked')]
    public function onEdgeSourceControlLinked(): void
    {
        if ($this->edge_deploy_ref_picker_open) {
            $this->refreshEdgeDeployRefs();
        }

        // Re-render re-evaluates edgeDeployRefMissingProvider() for the deploys table.
    }

    /**
     * Returns the provider id when the failed ref-load was caused by the
     * user lacking a linked OAuth account or PAT for the site's repo host —
     * lets the picker swap the rose error for the shared connect-provider CTA.
     *
     * @param  array<string, mixed>  $result
     */
    private function detectEdgeDeployRefProviderGap(User $user, array $result): ?string
    {
        $provider = (string) ($result['provider'] ?? '');
        if ($provider === '' || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return null;
        }

        return app(GitIdentityResolver::class)->forUserProvider($user, $provider) === null
            ? $provider
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function filterEdgeDeployRefs(array $rows, string $search, array $fields): array
    {
        if ($search === '') {
            return array_values(array_filter($rows, fn (array $row): bool => ($row['sha'] ?? '') !== ''));
        }

        return array_values(array_filter($rows, function (array $row) use ($search, $fields): bool {
            if (($row['sha'] ?? '') === '') {
                return false;
            }

            foreach ($fields as $field) {
                $value = mb_strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function rollbackEdgeDeployment(string $deploymentId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RollbackEdgeDeployment)->handle($this->site, $deploymentId);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Rolled back — the selected deployment is now live.'));
        }
    }

    /**
     * Opens the shared confirm-action modal for tearing down a preview. The
     * row button hits this; the modal then dispatches to {@see tearDownEdgePreview}
     * on confirm. Keeps the destructive action behind the same blocking dialog
     * the rest of the app uses (avoids the inconsistent native wire:confirm).
     */
    public function confirmTearDownEdgePreview(string $previewSiteId): void
    {
        if (! method_exists($this, 'openConfirmActionModal')) {
            // Fallback: components without the trait fire the action directly.
            $this->tearDownEdgePreview($previewSiteId);

            return;
        }

        $this->openConfirmActionModal(
            'tearDownEdgePreview',
            [$previewSiteId],
            __('Tear down this preview?'),
            __('The R2 artifacts and Edge hostname will be removed and the preview URL will stop responding. This cannot be undone — the preview can be re-created from the same commit afterwards.'),
            __('Tear down preview'),
            true,
        );
    }

    public function tearDownEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Preview not found or not a child of this site.'));
            }

            return;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);

        if (method_exists($this, 'toastSuccess')) {
            $branch = (string) ($preview->edgeMeta()['preview_branch'] ?? '');
            $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
        }
    }

    public function attachEdgeDomain(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->edge_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            $backend->attachDomain($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_domain_input = '';
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain attached. Configure DNS, then verify when ready.'));
        }
    }

    public function verifyEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($this->site->fresh(), $hostname);
        $this->site->refresh();

        $status = is_array($entry) ? (string) ($entry['dns_status'] ?? '') : '';
        if ($status === 'ready') {
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('DNS verified — :hostname is live on Edge.', ['hostname' => $hostname]));
            }

            return;
        }

        $error = is_array($entry) ? (string) ($entry['error'] ?? '') : '';
        if (method_exists($this, 'toastError')) {
            $this->toastError($error !== '' ? $error : __('DNS verification failed. Check your CNAME and try again.'));
        }
    }

    public function detachEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain removed.'));
        }
    }

    public function openEdgeTeardownModal(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);
        $this->dispatch('open-modal', 'edge-teardown-confirmation');
    }

    public function tearDownEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        TeardownEdgeSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge site teardown queued.'));
        }
    }

    /**
     * @return Collection<int, EdgeDeployment>
     */
    public function edgeDeploymentHistory(int $limit = 10): Collection
    {
        return $this->site->edgeDeployments()->limit($limit)->get();
    }

    /**
     * @return Collection<int, Site>
     */
    public function edgePreviewSites(): Collection
    {
        return CreateEdgePreviewSite::listForParent($this->site);
    }
}
