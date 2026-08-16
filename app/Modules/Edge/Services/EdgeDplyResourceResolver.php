<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves `dply.yaml` `bindings.dply` references — the wedge that makes an
 * Edge site the frontend tier of a full-stack dply app.
 *
 * A ref is `ENV_NAME: <kind>.<name>` (e.g. `DATABASE_URL: database.main`),
 * pointing at a sibling dply-managed resource in the SAME workspace (falling
 * back to the same organization). We resolve it against the sibling sites'
 * {@see SiteBinding} rows and classify how it can reach a Cloudflare Worker:
 *
 *   - public   — a managed/publicly-reachable resource (a Cloud database
 *                cluster, an object-storage bucket). Its connection string is
 *                safe to inject as a Worker `secret_text` binding.
 *   - private  — a resource on a private VM (a server_database on a box with a
 *                private host). We must NOT leak a private DSN into a Worker
 *                that runs on Cloudflare's edge; instead the app routes
 *                DB-backed dynamic logic through the hybrid origin. We resolve
 *                the ref so the UI can show it, but emit no secret.
 *   - unresolved — no sibling matched the ref.
 *
 * Resolution happens at deploy time (build validation + upload injection),
 * never in the context-free config loader, because it needs the Site.
 */
class EdgeDplyResourceResolver
{
    /** dply.yaml kind aliases → SiteBinding::TYPES value. */
    private const KIND_ALIASES = [
        'database' => 'database',
        'postgres' => 'database',
        'postgresql' => 'database',
        'pg' => 'database',
        'mysql' => 'database',
        'mariadb' => 'database',
        'mongodb' => 'database',
        'clickhouse' => 'database',
        'redis' => 'redis',
        'valkey' => 'redis',
        'queue' => 'queue',
        'storage' => 'storage',
        'bucket' => 'storage',
        's3' => 'storage',
        'spaces' => 'storage',
    ];

    /**
     * @param  array<string, string>  $refs  ENV_NAME => "<kind>.<name>"
     * @return list<array{env_name: string, ref: string, kind: string, name: string, status: string, value: ?string, warning: ?string}>
     */
    public function resolve(Site $edgeSite, array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $siblings = $this->siblingSites($edgeSite);
        $siblingIds = $siblings->pluck('id')->all();

        // Stack-aware previews: when the edge site is itself a PR/branch preview,
        // its `site.<name>` refs should bind to a matching preview backend (same
        // branch) rather than production. Resource kinds (db/redis/…) have no
        // per-preview counterpart today, so they still resolve to production.
        $previewBranch = null;
        if ($edgeSite->isEdgePreview()) {
            $branch = $edgeSite->edgeMeta()['preview_branch'] ?? null;
            $previewBranch = is_string($branch) && $branch !== '' ? $branch : null;
        }

        $out = [];
        foreach ($refs as $envName => $ref) {
            [$rawKind, $name] = array_pad(explode('.', $ref, 2), 2, '');
            $rawKind = strtolower(trim($rawKind));
            $name = trim($name);

            $base = ['env_name' => $envName, 'ref' => $ref, 'kind' => $rawKind, 'name' => $name];

            // `site`/`api` refs bind to a sibling backend Site's public URL —
            // the connective tissue of the full-stack app object (edge frontend
            // → your dply API → its DB).
            if (in_array($rawKind, ['site', 'api', 'service'], true)) {
                $out[] = $base + $this->resolveSiteRef($siblings, $name, $previewBranch);

                continue;
            }

            $type = self::KIND_ALIASES[$rawKind] ?? null;
            if ($type === null || $name === '') {
                $out[] = $base + ['status' => 'unresolved', 'value' => null, 'warning' => "unknown resource kind '{$rawKind}'."];

                continue;
            }

            $binding = $siblingIds === []
                ? null
                : SiteBinding::query()
                    ->whereIn('site_id', $siblingIds)
                    ->where('type', $type)
                    ->where('name', $name)
                    ->where('status', SiteBinding::STATUS_CONFIGURED)
                    ->first();

            if ($binding === null) {
                $out[] = $base + ['status' => 'unresolved', 'value' => null, 'warning' => "no {$type} named '{$name}' in this workspace."];

                continue;
            }

            if ($this->isPrivate($binding)) {
                $out[] = $base + ['status' => 'private', 'value' => null, 'warning' => 'lives on a private VM — not directly reachable from the edge; route it through hybrid `origin:`.'];

                continue;
            }

            $url = $this->connectionUrlFor($binding, $type);
            if ($url === null) {
                $out[] = $base + ['status' => 'unresolved', 'value' => null, 'warning' => "resolved the {$type} but could not derive a connection URL."];

                continue;
            }

            $out[] = $base + ['status' => 'public', 'value' => $url, 'warning' => null];
        }

        return $out;
    }

    /**
     * Sibling sites in the same workspace (fallback: same organization),
     * excluding the Edge site itself.
     *
     * @return Collection<int, Site>
     */
    private function siblingSites(Site $edgeSite): Collection
    {
        $query = Site::query()->where('id', '!=', $edgeSite->id);

        if ($edgeSite->workspace_id !== null) {
            $query->where('workspace_id', $edgeSite->workspace_id);
        } elseif ($edgeSite->organization_id !== null) {
            $query->where('organization_id', $edgeSite->organization_id);
        } else {
            return collect();
        }

        return $query->get();
    }

    /**
     * Resolve a `site.<name>`/`api.<name>` ref to a sibling backend Site's
     * public URL. Matches by slug then slugified name (mirrors
     * HybridEdgeOriginMatcher::findForEdgeName).
     *
     * @param  Collection<int, Site>  $siblings
     * @return array{status: string, value: ?string, warning: ?string}
     */
    private function resolveSiteRef(Collection $siblings, string $name, ?string $previewBranch = null): array
    {
        if ($name === '') {
            return ['status' => 'unresolved', 'value' => null, 'warning' => 'missing site name (use site.<name>).'];
        }

        $match = $this->matchSite($siblings, $name);

        if ($match === null) {
            return ['status' => 'unresolved', 'value' => null, 'warning' => "no site named '{$name}' in this workspace."];
        }

        // Prefer a live preview of this backend on the same branch (stack-aware
        // previews). The preview's slug is mangled (pr-123-api), so we match the
        // PRODUCTION backend by name first, then hop to its branch preview.
        $target = $match;
        $note = null;
        if ($previewBranch !== null) {
            $preview = $this->previewBackendFor($siblings, $match, $previewBranch);
            if ($preview !== null) {
                $target = $preview;
                $note = "→ preview backend for branch '{$previewBranch}'";
            }
        }

        $url = $this->siteUrl($target);
        if ($url === null) {
            return ['status' => 'unresolved', 'value' => null, 'warning' => "site '{$name}' has no public URL yet — deploy it first."];
        }

        return ['status' => 'public', 'value' => $url, 'warning' => $note];
    }

    /**
     * A live Cloud preview of $prod on $branch, drawn from the already-loaded
     * workspace siblings (kernel Site meta only — no Cloud-module dependency).
     *
     * @param  Collection<int, Site>  $siblings
     */
    private function previewBackendFor(Collection $siblings, Site $prod, string $branch): ?Site
    {
        return $siblings->first(function (Site $s) use ($prod, $branch): bool {
            $container = is_array($s->meta['container'] ?? null) ? $s->meta['container'] : [];

            return ($container['preview_parent_site_id'] ?? null) === $prod->id
                && ($container['preview_branch'] ?? null) === $branch
                && ($container['torn_down_at'] ?? null) === null;
        });
    }

    /**
     * The sibling backend Sites referenced by `site.<name>`/`api.<name>` refs.
     * Used by the deploy-contract backend-health gate (which needs the Site
     * objects, not just their URLs).
     *
     * @param  array<string, string>  $refs
     * @return Collection<int, Site>
     */
    public function resolveBackendSites(Site $edgeSite, array $refs): Collection
    {
        $names = [];
        foreach ($refs as $ref) {
            [$kind, $name] = array_pad(explode('.', $ref, 2), 2, '');
            if (in_array(strtolower(trim($kind)), ['site', 'api', 'service'], true) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        if ($names === []) {
            return collect();
        }

        $siblings = $this->siblingSites($edgeSite);

        return collect($names)
            ->map(fn (string $name): ?Site => $this->matchSite($siblings, $name))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Match a sibling Site by slug, then slugified slug/name.
     *
     * @param  Collection<int, Site>  $siblings
     */
    private function matchSite(Collection $siblings, string $name): ?Site
    {
        if ($name === '') {
            return null;
        }

        $wanted = Str::slug($name);

        return $siblings->first(
            fn (Site $s): bool => $s->slug === $name
                || Str::slug((string) $s->slug) === $wanted
                || Str::slug((string) $s->name) === $wanted,
        );
    }

    /**
     * A site's public https URL, whichever backend it runs on: managed
     * container, VM webserver, or Edge.
     */
    private function siteUrl(Site $site): ?string
    {
        foreach (['containerLiveUrl', 'visitUrl', 'edgeLiveUrl'] as $method) {
            $url = $site->{$method}();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * A binding is edge-unreachable when its target sits on a private VM. Cloud
     * (managed) databases and object-storage buckets have public endpoints.
     */
    private function isPrivate(SiteBinding $binding): bool
    {
        if ($binding->type === 'storage') {
            return false; // bucket endpoints are public
        }

        if ($binding->target_type === 'cloud_database') {
            return false; // managed cluster — public host
        }

        if ($binding->target_type === 'server_database') {
            return true; // on a box — private host
        }

        // Unknown target: trust an explicit placement hint, else assume private.
        return in_array($binding->config['placement'] ?? '', ['dedicated_vm', 'docker', 'docker_vm', ''], true);
    }

    /**
     * Pull a single connection URL from the binding's (encrypted) connection
     * env, preferring an explicit `*_URL` value, then composing one for redis.
     */
    private function connectionUrlFor(SiteBinding $binding, string $type): ?string
    {
        $env = $binding->connectionEnv();

        $preferred = match ($type) {
            'database' => 'DATABASE_URL',
            'redis' => 'REDIS_URL',
            default => null,
        };
        if ($preferred !== null && ($env[$preferred] ?? '') !== '') {
            return $env[$preferred];
        }

        // Compose redis:// from the discrete REDIS_* vars when no URL is stored.
        if ($type === 'redis' && ($env['REDIS_HOST'] ?? '') !== '') {
            $auth = ($env['REDIS_PASSWORD'] ?? '') !== '' ? ':'.rawurlencode($env['REDIS_PASSWORD']).'@' : '';

            return sprintf('redis://%s%s:%s', $auth, $env['REDIS_HOST'], $env['REDIS_PORT'] ?? '6379');
        }

        // Generic fallback — first `*_URL` the binding contributes.
        foreach ($env as $key => $value) {
            if (str_ends_with($key, '_URL') && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
