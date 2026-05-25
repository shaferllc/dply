<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Services\Edge\EdgeCloudflareClient;
use App\Services\Edge\EdgeDeliveryContextResolver;
use App\Support\Edge\EdgeDeliveryContext;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * RUNTIME > Bindings — interactive create/attach UI for Cloudflare resources
 * (KV namespaces, R2 buckets, D1 databases) declared by a site's `dply.yaml`.
 *
 * Lists declared bindings from the latest live deployment snapshot side-by-side
 * with what exists on the Cloudflare account. Operators "Create new" via the
 * API or "Attach existing" by picking a resource — both flows produce a
 * `dply.yaml` snippet for the user to paste; we never write to their repo.
 *
 * Mounts via the dedicated `sites.edge-bindings` route so the operator gets
 * an addressable URL per tab (KV / R2 / D1) and the surface can grow Queues
 * + Hyperdrive later without bloating the SiteSettings component.
 */
#[Layout('layouts.app')]
class EdgeBindings extends Component
{
    use DispatchesToastNotifications;

    /** Cache TTL for Cloudflare list calls. Short enough that "Create" -> "list" feels live. */
    private const LIST_CACHE_TTL = 15;

    public Server $server;

    public Site $site;

    #[Url(as: 'kind', except: 'kv')]
    public string $kind = 'kv';

    public string $newKvTitle = '';

    public string $newR2Name = '';

    public string $newD1Name = '';

    public string $newD1LocationHint = 'wnam';

    /**
     * Which resource was just created/attached — drives the "paste this into
     * your dply.yaml" snippet panel. Shape:
     *   ['kind' => 'kv'|'r2'|'d1', 'binding' => 'MY_VAR', 'id'? => string, 'name' => string].
     *
     * @var array<string, mixed>|null
     */
    public ?array $lastSnippet = null;

    /** Error string from the most recent Cloudflare list call, surfaced once in the view. */
    public ?string $listError = null;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        if (! $site->usesEdgeRuntime()) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
    }

    public function setKind(string $kind): void
    {
        if (! in_array($kind, ['kv', 'r2', 'd1'], true)) {
            return;
        }

        $this->kind = $kind;
        $this->lastSnippet = null;
    }

    /* ──────────── KV namespaces ──────────── */

    public function createKvNamespace(): void
    {
        Gate::authorize('update', $this->site);

        $title = trim($this->newKvTitle);
        if ($title === '' || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $title)) {
            $this->toastError(__('Namespace title must be 1-64 chars (letters, digits, dot, dash, underscore).'));

            return;
        }

        try {
            $client = $this->cloudflareClient();
            $result = $client->createKvNamespace($title);
        } catch (Throwable $e) {
            $this->toastError($this->humanizeCloudflareError($e, __('Could not create KV namespace.')));

            return;
        }

        $namespaceId = (string) ($result['id'] ?? '');
        $this->lastSnippet = [
            'kind' => 'kv',
            'binding' => $this->suggestBindingName('KV', $title),
            'id' => $namespaceId,
            'name' => $title,
        ];

        $this->logCreation('kv', $title, $namespaceId);
        $this->forgetListCache('kv');
        $this->newKvTitle = '';
        $this->toastSuccess(__('KV namespace ":title" created. Paste the snippet into dply.yaml to bind it.', ['title' => $title]));
    }

    public function attachKvNamespace(string $namespaceId, string $title): void
    {
        Gate::authorize('update', $this->site);

        if ($namespaceId === '' || $title === '') {
            $this->toastError(__('That namespace is missing an id or title.'));

            return;
        }

        $this->lastSnippet = [
            'kind' => 'kv',
            'binding' => $this->suggestBindingName('KV', $title),
            'id' => $namespaceId,
            'name' => $title,
        ];

        $this->toastSuccess(__('Snippet ready — paste it into dply.yaml under bindings.'));
    }

    /* ──────────── R2 buckets ──────────── */

    public function createR2Bucket(): void
    {
        Gate::authorize('update', $this->site);

        $name = strtolower(trim($this->newR2Name));
        if ($name === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/', $name)) {
            $this->toastError(__('Bucket name must be 3-64 chars, lowercase letters/digits/dashes, no leading/trailing dash.'));

            return;
        }

        try {
            $client = $this->cloudflareClient();
            $client->createR2Bucket($name);
        } catch (Throwable $e) {
            $this->toastError($this->humanizeCloudflareError($e, __('Could not create R2 bucket.')));

            return;
        }

        $this->lastSnippet = [
            'kind' => 'r2',
            'binding' => $this->suggestBindingName('BUCKET', $name),
            'name' => $name,
        ];

        $this->logCreation('r2', $name, null);
        $this->forgetListCache('r2');
        $this->newR2Name = '';
        $this->toastSuccess(__('R2 bucket ":name" created. Paste the snippet into dply.yaml.', ['name' => $name]));
    }

    public function attachR2Bucket(string $name): void
    {
        Gate::authorize('update', $this->site);

        if ($name === '') {
            $this->toastError(__('Bucket name missing.'));

            return;
        }

        $this->lastSnippet = [
            'kind' => 'r2',
            'binding' => $this->suggestBindingName('BUCKET', $name),
            'name' => $name,
        ];

        $this->toastSuccess(__('Snippet ready — paste it into dply.yaml under bindings.'));
    }

    /* ──────────── D1 databases ──────────── */

    public function createD1Database(): void
    {
        Gate::authorize('update', $this->site);

        $name = trim($this->newD1Name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            $this->toastError(__('D1 database name must be 1-64 chars (letters, digits, dash, underscore).'));

            return;
        }

        $hint = trim($this->newD1LocationHint) !== '' ? trim($this->newD1LocationHint) : 'wnam';

        try {
            $client = $this->cloudflareClient();
            $result = $client->createD1Database($name, $hint);
        } catch (Throwable $e) {
            $this->toastError($this->humanizeCloudflareError($e, __('Could not create D1 database.')));

            return;
        }

        $databaseId = (string) ($result['uuid'] ?? ($result['id'] ?? ''));
        $this->lastSnippet = [
            'kind' => 'd1',
            'binding' => $this->suggestBindingName('DB', $name),
            'id' => $databaseId,
            'name' => $name,
        ];

        $this->logCreation('d1', $name, $databaseId);
        $this->forgetListCache('d1');
        $this->newD1Name = '';
        $this->toastSuccess(__('D1 database ":name" created. Paste the snippet into dply.yaml.', ['name' => $name]));
    }

    public function attachD1Database(string $databaseId, string $name): void
    {
        Gate::authorize('update', $this->site);

        if ($databaseId === '' || $name === '') {
            $this->toastError(__('That database is missing an id or name.'));

            return;
        }

        $this->lastSnippet = [
            'kind' => 'd1',
            'binding' => $this->suggestBindingName('DB', $name),
            'id' => $databaseId,
            'name' => $name,
        ];

        $this->toastSuccess(__('Snippet ready — paste it into dply.yaml under bindings.'));
    }

    public function dismissSnippet(): void
    {
        $this->lastSnippet = null;
    }

    public function render(): View
    {
        $context = $this->resolveContextSilently();

        $kvNamespaces = [];
        $r2Buckets = [];
        $d1Databases = [];
        $this->listError = null;

        if ($context !== null) {
            $client = new EdgeCloudflareClient($context->accountId, $context->apiToken);

            // Only fetch the list for the currently selected tab — keeps render
            // cheap when the operator is toggling between kinds and we already
            // cached the previous result anyway.
            if ($this->kind === 'kv') {
                try {
                    $kvNamespaces = $this->cachedList($context, 'kv', fn () => $client->listKvNamespaces());
                } catch (Throwable $e) {
                    $this->listError = $this->humanizeCloudflareError($e, __('Could not list KV namespaces.'));
                }
            }

            if ($this->kind === 'r2') {
                try {
                    $r2Buckets = $this->cachedList($context, 'r2', fn () => $client->listR2Buckets());
                } catch (Throwable $e) {
                    $this->listError = $this->humanizeCloudflareError($e, __('Could not list R2 buckets.'));
                }
            }

            if ($this->kind === 'd1') {
                try {
                    $d1Databases = $this->cachedList($context, 'd1', fn () => $client->listD1Databases());
                } catch (Throwable $e) {
                    $this->listError = $this->humanizeCloudflareError($e, __('Could not list D1 databases.'));
                }
            }
        }

        return view('livewire.sites.edge-bindings', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => __('Site'),
            'resourcePlural' => __('sites'),
            'section' => 'edge-bindings',
            'laravel_tab' => 'commands',
            'routingTab' => 'domains',
            'kvNamespaces' => $kvNamespaces,
            'r2Buckets' => $r2Buckets,
            'd1Databases' => $d1Databases,
            'declaredBindings' => $this->declaredBindings(),
            'contextResolved' => $context !== null,
            'snippetYaml' => $this->lastSnippet !== null ? $this->renderSnippet($this->lastSnippet) : null,
        ]);
    }

    /* ──────────── helpers ──────────── */

    private function cloudflareClient(): EdgeCloudflareClient
    {
        $context = app(EdgeDeliveryContextResolver::class)->forSite($this->site);

        return new EdgeCloudflareClient($context->accountId, $context->apiToken);
    }

    private function resolveContextSilently(): ?EdgeDeliveryContext
    {
        try {
            $context = app(EdgeDeliveryContextResolver::class)->forSite($this->site);

            return $context->isBootstrapped() ? $context : null;
        } catch (Throwable $e) {
            $this->listError = $this->humanizeCloudflareError($e, __('Edge delivery is not configured for this site.'));

            return null;
        }
    }

    /**
     * @template T
     *
     * @param  callable():T  $loader
     * @return T
     */
    private function cachedList(EdgeDeliveryContext $context, string $kind, callable $loader): mixed
    {
        return Cache::remember(
            $this->listCacheKey($context, $kind),
            self::LIST_CACHE_TTL,
            $loader,
        );
    }

    private function forgetListCache(string $kind): void
    {
        try {
            $context = app(EdgeDeliveryContextResolver::class)->forSite($this->site);
        } catch (Throwable) {
            return;
        }

        Cache::forget($this->listCacheKey($context, $kind));
    }

    private function listCacheKey(EdgeDeliveryContext $context, string $kind): string
    {
        return sprintf('edge.bindings.list.%s.%s.%s', $context->accountId, $kind, $this->site->id);
    }

    /**
     * Bindings the operator has already declared in `dply.yaml`, lifted from
     * the most recent live deployment's snapshot (or the latest attempt as a
     * fallback). Returns shape:
     *   ['kv' => [['binding' => 'X', 'id' => '...'], ...], 'r2' => [...], 'd1' => [...]].
     *
     * Tolerant of branches where the `repo_config` column / loader hasn't
     * shipped yet — falls back to scanning `meta.bindings` on the deployment.
     *
     * @return array{kv: list<array<string, string>>, r2: list<array<string, string>>, d1: list<array<string, string>>}
     */
    private function declaredBindings(): array
    {
        $deployment = $this->site->edgeDeployments()
            ->whereIn('status', [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED])
            ->first();

        $deployment ??= $this->site->edgeDeployments()->first();

        $bindings = [];
        if ($deployment !== null) {
            $repoConfig = is_array($deployment->getAttribute('repo_config') ?? null)
                ? $deployment->getAttribute('repo_config')
                : null;
            $meta = is_array($deployment->meta) ? $deployment->meta : [];

            $bindings = $repoConfig['bindings']
                ?? $meta['repo_config']['bindings']
                ?? $meta['bindings']
                ?? [];
        }

        if (! is_array($bindings)) {
            $bindings = [];
        }

        return [
            'kv' => $this->normalizeDeclaredList($bindings['kv'] ?? [], ['binding', 'id']),
            'r2' => $this->normalizeDeclaredList($bindings['r2'] ?? [], ['binding', 'bucket', 'name']),
            'd1' => $this->normalizeDeclaredList($bindings['d1'] ?? [], ['binding', 'id', 'database_id']),
        ];
    }

    /**
     * @param  mixed  $source
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private function normalizeDeclaredList($source, array $keys): array
    {
        if (! is_array($source)) {
            return [];
        }

        $out = [];
        foreach ($source as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $row = [];
            foreach ($keys as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $row[$key] = $value;
                }
            }

            if ($row !== []) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Suggest a binding identifier (UPPER_SNAKE) from a resource name; keeps
     * the operator from having to invent one in the snippet panel.
     */
    private function suggestBindingName(string $prefix, string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?: $prefix;
        $slug = trim((string) $slug, '_');
        $slug = strtoupper($slug);

        if ($slug === '' || ! preg_match('/^[A-Z]/', $slug)) {
            $slug = $prefix.($slug === '' ? '' : '_'.$slug);
        }

        return substr($slug, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $snippet
     */
    private function renderSnippet(array $snippet): string
    {
        $binding = (string) ($snippet['binding'] ?? 'MY_BINDING');
        $name = (string) ($snippet['name'] ?? '');
        $id = (string) ($snippet['id'] ?? '');

        return match ((string) ($snippet['kind'] ?? '')) {
            'kv' => "bindings:\n  kv:\n    - binding: {$binding}\n      id: {$id}\n",
            'r2' => "bindings:\n  r2:\n    - binding: {$binding}\n      bucket: {$name}\n",
            'd1' => "bindings:\n  d1:\n    - binding: {$binding}\n      database_id: {$id}\n      database_name: {$name}\n",
            default => '',
        };
    }

    private function humanizeCloudflareError(Throwable $e, string $fallback): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            return $fallback;
        }

        // RuntimeException from EdgeCloudflareClient::decode already carries
        // a human-readable Cloudflare error.message — surface it as-is.
        return $msg;
    }

    private function logCreation(string $kind, string $name, ?string $cfId): void
    {
        $org = $this->site->organization;
        if ($org === null) {
            return;
        }

        audit_log(
            $org,
            auth()->user(),
            'site.edge.bindings.created',
            $this->site,
            null,
            array_filter([
                'kind' => $kind,
                'name' => $name,
                'cf_id' => $cfId,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }
}
