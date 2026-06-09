<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Services\Edge\EdgeHostMapPublisher;
use App\Support\Edge\EdgeEffectiveRouting;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Routing editor — same pattern as Crons / Firewall. Repo-declared
 * redirects/rewrites/headers (from dply.yaml) are read-only; dashboard
 * rows store on edgeMeta.routing_overrides and merge additively at
 * deploy time via EdgeEffectiveRouting. Saving immediately republishes
 * the host map so dashboard edits go live without a redeploy.
 */
class Routing extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    /** @var list<array{from: string, to: string, status: int}> */
    public array $dashboard_redirects = [];

    /** @var list<array{from: string, to: string}> */
    public array $dashboard_rewrites = [];

    /** @var list<array{for: string, values: array<string, string>}> */
    public array $dashboard_headers = [];

    // Add-row fields
    public string $new_redirect_from = '';

    public string $new_redirect_to = '';

    public int $new_redirect_status = 301;

    public string $new_rewrite_from = '';

    public string $new_rewrite_to = '';

    public string $new_header_for = '';

    public string $new_header_pairs = '';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshFromMeta();
    }

    private function refreshFromMeta(): void
    {
        $overrides = is_array($this->site->edgeMeta()['routing_overrides'] ?? null) ? $this->site->edgeMeta()['routing_overrides'] : [];

        $this->dashboard_redirects = array_values(array_map(
            static fn ($r): array => [
                'from' => (string) ($r['from'] ?? ''),
                'to' => (string) ($r['to'] ?? ''),
                'status' => (int) ($r['status'] ?? 301),
            ],
            array_filter(is_array($overrides['redirects'] ?? null) ? $overrides['redirects'] : [], static fn ($r): bool => is_array($r)),
        ));

        $this->dashboard_rewrites = array_values(array_map(
            static fn ($r): array => [
                'from' => (string) ($r['from'] ?? ''),
                'to' => (string) ($r['to'] ?? ''),
            ],
            array_filter(is_array($overrides['rewrites'] ?? null) ? $overrides['rewrites'] : [], static fn ($r): bool => is_array($r)),
        ));

        $this->dashboard_headers = array_values(array_map(
            static fn ($r): array => [
                'for' => (string) ($r['for'] ?? ''),
                'values' => is_array($r['values'] ?? null) ? $r['values'] : [],
            ],
            array_filter(is_array($overrides['headers'] ?? null) ? $overrides['headers'] : [], static fn ($r): bool => is_array($r)),
        ));
    }

    public function addRedirect(): void
    {
        $this->authorize('update', $this->site);

        $from = trim($this->new_redirect_from);
        $to = trim($this->new_redirect_to);
        $status = (int) $this->new_redirect_status;
        if ($from === '' || $to === '') {
            $this->addError('new_redirect_from', __('Both from and to are required.'));

            return;
        }
        if ($status < 300 || $status > 399) {
            $status = 301;
        }
        if (! str_starts_with($from, '/')) {
            $this->addError('new_redirect_from', __('From must start with /'));

            return;
        }

        $this->dashboard_redirects[] = ['from' => $from, 'to' => $to, 'status' => $status];
        $this->persist('redirect.added');
        $this->new_redirect_from = '';
        $this->new_redirect_to = '';
        $this->new_redirect_status = 301;
    }

    public function removeRedirect(int $index): void
    {
        $this->authorize('update', $this->site);
        if (! isset($this->dashboard_redirects[$index])) {
            return;
        }
        array_splice($this->dashboard_redirects, $index, 1);
        $this->persist('redirect.removed');
    }

    public function addRewrite(): void
    {
        $this->authorize('update', $this->site);

        $from = trim($this->new_rewrite_from);
        $to = trim($this->new_rewrite_to);
        if ($from === '' || $to === '') {
            $this->addError('new_rewrite_from', __('Both from and to are required.'));

            return;
        }
        if (! str_starts_with($from, '/')) {
            $this->addError('new_rewrite_from', __('From must start with /'));

            return;
        }

        $this->dashboard_rewrites[] = ['from' => $from, 'to' => $to];
        $this->persist('rewrite.added');
        $this->new_rewrite_from = '';
        $this->new_rewrite_to = '';
    }

    public function removeRewrite(int $index): void
    {
        $this->authorize('update', $this->site);
        if (! isset($this->dashboard_rewrites[$index])) {
            return;
        }
        array_splice($this->dashboard_rewrites, $index, 1);
        $this->persist('rewrite.removed');
    }

    public function addHeaderRule(): void
    {
        $this->authorize('update', $this->site);

        $for = trim($this->new_header_for);
        $pairs = $this->parseHeaderPairs($this->new_header_pairs);
        if ($for === '' || $pairs === []) {
            $this->addError('new_header_for', __('Pattern + at least one Header: value pair required.'));

            return;
        }
        if (! str_starts_with($for, '/')) {
            $this->addError('new_header_for', __('Pattern must start with /'));

            return;
        }

        $this->dashboard_headers[] = ['for' => $for, 'values' => $pairs];
        $this->persist('headers.added');
        $this->new_header_for = '';
        $this->new_header_pairs = '';
    }

    public function removeHeaderRule(int $index): void
    {
        $this->authorize('update', $this->site);
        if (! isset($this->dashboard_headers[$index])) {
            return;
        }
        array_splice($this->dashboard_headers, $index, 1);
        $this->persist('headers.removed');
    }

    public function applyTemplate(string $key): void
    {
        $this->authorize('update', $this->site);

        $tpl = self::templates()[$key] ?? null;
        if ($tpl === null) {
            return;
        }
        foreach ($tpl['redirects'] ?? [] as $r) {
            $this->dashboard_redirects[] = $r;
        }
        foreach ($tpl['rewrites'] ?? [] as $r) {
            $this->dashboard_rewrites[] = $r;
        }
        foreach ($tpl['headers'] ?? [] as $r) {
            $this->dashboard_headers[] = $r;
        }
        $this->persist('template.applied');
        $this->toastSuccess(__('Applied :label — review the rules and click any to remove.', ['label' => $tpl['label']]));
    }

    private function persist(string $action): void
    {
        $previous = is_array($this->site->edgeMeta()['routing_overrides'] ?? null) ? $this->site->edgeMeta()['routing_overrides'] : [];

        $merged = [
            'redirects' => $this->dashboard_redirects,
            'rewrites' => $this->dashboard_rewrites,
            'headers' => $this->dashboard_headers,
        ];
        $merged = array_filter($merged, static fn (array $list): bool => $list !== []);

        $this->site->mergeEdgeMeta(['routing_overrides' => $merged]);
        $this->site->save();

        // Republish host map so changes take effect without a redeploy.
        try {
            $live = EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('id')
                ->first();
            if ($live !== null) {
                app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $live);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.routing.'.$action,
            $this->site,
            ['routing_overrides' => $previous],
            ['routing_overrides' => $merged],
        );
    }

    /** @return array<string, string> */
    private function parseHeaderPairs(string $raw): array
    {
        $pairs = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($name === '' || $value === '') {
                continue;
            }
            $pairs[$name] = $value;
        }

        return $pairs;
    }

    public function render(): View
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $effective = EdgeEffectiveRouting::for($this->site, $latest);
        $repoRedirects = array_values(array_filter($effective['redirects'], static fn (array $r): bool => $r['source'] === 'repo'));
        $repoRewrites = array_values(array_filter($effective['rewrites'], static fn (array $r): bool => $r['source'] === 'repo'));
        $repoHeaders = array_values(array_filter($effective['headers'], static fn (array $r): bool => $r['source'] === 'repo'));

        $sourcePath = is_array($latest?->repo_config) && is_string($latest->repo_config['source_path'] ?? null)
            ? (string) $latest->repo_config['source_path']
            : 'dply.yaml';

        return view('livewire.sites.edge.workspace.routing', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-routing'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoRedirects' => $repoRedirects,
                'repoRewrites' => $repoRewrites,
                'repoHeaders' => $repoHeaders,
                'sourcePath' => $sourcePath,
                'templates' => self::templates(),
            ],
        ));
    }

    /**
     * Starter rule sets — common patterns a user can apply in one click,
     * then prune what they don't need.
     *
     * @return array<string, array{label: string, hint: string, redirects?: list<array{from: string, to: string, status: int}>, rewrites?: list<array{from: string, to: string}>, headers?: list<array{for: string, values: array<string, string>}>}>
     */
    private static function templates(): array
    {
        return [
            'security-headers' => [
                'label' => __('Security headers'),
                'hint' => __('Adds X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Strict-Transport-Security across the entire site.'),
                'headers' => [
                    [
                        'for' => '/*',
                        'values' => [
                            'X-Content-Type-Options' => 'nosniff',
                            'X-Frame-Options' => 'SAMEORIGIN',
                            'Referrer-Policy' => 'strict-origin-when-cross-origin',
                            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                        ],
                    ],
                ],
            ],
            'cache-assets' => [
                'label' => __('Long-cache static assets'),
                'hint' => __('Adds a year-long immutable Cache-Control on /assets/* (works with content-hashed filenames).'),
                'headers' => [
                    [
                        'for' => '/assets/*',
                        'values' => [
                            'Cache-Control' => 'public, max-age=31536000, immutable',
                        ],
                    ],
                ],
            ],
            'api-proxy' => [
                'label' => __('Proxy /api/* to an upstream'),
                'hint' => __('Forwards /api/* to a remote origin (edit the destination after applying).'),
                'rewrites' => [
                    ['from' => '/api/*', 'to' => 'https://api.example.com/:splat'],
                ],
            ],
            'blog-migration' => [
                'label' => __('Blog URL migration (301)'),
                'hint' => __('301-redirects /blog/* and /old-page to new paths. A classic SEO-preserving migration starter.'),
                'redirects' => [
                    ['from' => '/old-page', 'to' => '/new-page', 'status' => 301],
                    ['from' => '/blog/*', 'to' => '/news/:splat', 'status' => 301],
                ],
            ],
        ];
    }
}
