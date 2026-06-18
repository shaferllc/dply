<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\PrimaryHostnameRenamePlanner;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only previewer for the cascade triggered when an operator switches a
 * server's webserver (nginx → caddy / apache / openlitespeed / traefik).
 *
 * Returns a structured array the Livewire layer renders in the confirmation
 * modal. No side effects, no remote SSH.
 *
 * Drives the "Switch webserver" UX on `/servers/{srv}/manage/web`. Mirrors
 * the rename-cascade planner pattern from {@see PrimaryHostnameRenamePlanner}.
 */
final class WebserverSwitchPreflight
{
    /**
     * Webservers dply knows how to provision. Order is rendered top-to-bottom
     * in the picker. Source of truth for "what target keys are valid."
     *
     * @var list<string>
     */
    public const KNOWN_WEBSERVERS = [
        'nginx',
        'caddy',
        'apache',
        'openlitespeed',
    ];

    /**
     * Webservers that proxy to PHP-FPM via FastCGI. Sites on FPM stay put when
     * switching among these — the new webserver just rewires its upstream.
     *
     * @var list<string>
     */
    private const FPM_COMPATIBLE = ['nginx', 'caddy', 'apache'];

    /**
     * Per-instance memo of plan() results keyed by `{server_id}|{target}`.
     * Why: the webserver picker blade calls isBlocked()/plan() once per known
     * webserver across multiple loops in a single render — without this each
     * call re-issues the sites + profiles + certificates SELECTs.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $planCache = [];

    /**
     * Per-instance cache of the sites collection (with certificates + profiles
     * eager-loaded) keyed by server_id. Shared across all target evaluations
     * for the same server since the underlying rows don't depend on target.
     *
     * @var array<string, Collection<int, Site>>
     */
    private array $sitesCache = [];

    /**
     * Per-instance cache of varnish-running rows keyed by server_id. The
     * varnish blocker check runs inside detectBlocker(), once per target
     * the picker shows — without this every non-active engine pays for its
     * own varnish select even though the answer is identical across targets.
     *
     * @var array<string, ServerCacheService|null>
     */
    private array $varnishCache = [];

    /**
     * Compute the cascade preview for switching {old} → {new}. Returns the
     * shape consumed by `livewire.servers.partials.manage.group-web` and
     * `WorkspaceManage::confirmSwitchWebserver()`.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   blocker: array{key: string, label: string, detail?: array<string, mixed>}|null,
     *   auto: list<array{key: string, label: string}>,
     *   optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>,
     *   manual: list<string>,
     *   sites_affected: int,
     *   downtime: list<array{phase: string, label: string, estimate_ms: int, blocking: bool}>,
     *   drift_sites: list<array{id: string, name: string, reason: string}>,
     * }
     */
    /** @return array<string, mixed> */
    public function plan(Server $server, string $target): array
    {
        $cacheKey = $server->id.'|'.strtolower(trim($target));
        if (isset($this->planCache[$cacheKey])) {
            return $this->planCache[$cacheKey];
        }

        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
        $to = strtolower(trim($target));

        $sites = $this->sitesFor($server);
        $sitesAffected = $sites->count();
        $driftSites = $this->detectCustomConfigDrift($sites);

        $blocker = $this->detectBlocker($from, $to, $sites, $server);

        $auto = [
            ['key' => 'install', 'label' => __('Install :target (apt/package)', ['target' => $to])],
            ['key' => 'provision', 'label' => __('Regenerate :n site config(s) under :target', ['n' => $sitesAffected, 'target' => $to])],
            ['key' => 'validate', 'label' => __(':target serves a test request on :8080 before cutover', ['target' => ucfirst($to)])],
            ['key' => 'cutover', 'label' => __('Service-swap to :80 (brief blip)')],
            ['key' => 'disable_old', 'label' => __('Stop and disable :from (kept installed for manual rollback)', ['from' => $from])],
            ['key' => 'audit', 'label' => __('Record audit event')],
        ];

        $optIn = [];
        if ($to === 'caddy' && $this->hasAnyTlsSites($sites)) {
            $optIn[] = [
                'key' => 'tls_to_caddy',
                'label' => __('Let Caddy manage TLS via auto-HTTPS (disables certbot renewal)'),
            ];
        }

        $manual = [
            __('Brief connection blip during cutover (computed below).'),
            __('Slack/webhook receivers that cached the `Server: :from` response header will see the change.', ['from' => $from]),
            __('External integrations / monitoring tools that fingerprint the webserver will see the change.'),
        ];
        if ($driftSites !== []) {
            // Custom config drift goes in manual because dply can't auto-port
            // hand-edited directives across webserver syntaxes. Operator owns
            // the re-port after the switch settles.
            $names = collect($driftSites)->pluck('name')->take(3)->implode(', ');
            $remainder = count($driftSites) - 3;
            $suffix = $remainder > 0 ? __(' (+:n more)', ['n' => $remainder]) : '';
            array_unshift($manual, __(
                ':n site(s) have hand-edited :from directives that will NOT carry over to :to: :names:suffix',
                [
                    'n' => count($driftSites),
                    'from' => $from,
                    'to' => $to,
                    'names' => $names,
                    'suffix' => $suffix,
                ]
            ));
        }

        $downtime = $this->downtimeBreakdown($sitesAffected, $to);

        return $this->planCache[$cacheKey] = [
            'from' => $from,
            'to' => $to,
            'blocker' => $blocker,
            'auto' => $auto,
            'optIn' => $optIn,
            'manual' => $manual,
            'sites_affected' => $sitesAffected,
            'downtime' => $downtime,
            'drift_sites' => $driftSites,
        ];
    }

    /**
     * Detect per-site custom config drift via {@see SiteWebserverConfigProfile}.
     * A site has drift when:
     *   - mode = 'full_override' (operator pasted their own complete config), OR
     *   - any of before_body / main_snippet_body / after_body is non-empty in
     *     layered mode (operator added custom directives around dply's template).
     *
     * Custom directives are written in the SOURCE webserver's syntax (nginx
     * location blocks, apache .htaccess, etc.) and won't auto-port to the
     * TARGET webserver — operator has to re-port manually after the switch.
     *
     * @param  Collection<int, Site>  $sites
     * @return list<array{id: string, name: string, reason: string}>
     */
    private function detectCustomConfigDrift($sites): array
    {
        $drift = [];
        foreach ($sites as $site) {
            $profile = $site->webserverConfigProfile;
            if ($profile === null) {
                continue;
            }

            $reason = null;
            if ($profile->mode === SiteWebserverConfigProfile::MODE_FULL_OVERRIDE
                && trim((string) $profile->full_override_body) !== '') {
                $reason = 'full_override';
            } elseif (trim((string) $profile->before_body) !== ''
                || trim((string) $profile->main_snippet_body) !== ''
                || trim((string) $profile->after_body) !== '') {
                $reason = 'layered_customizations';
            }

            if ($reason !== null) {
                $drift[] = [
                    'id' => (string) $site->id,
                    'name' => (string) $site->name,
                    'reason' => $reason,
                ];
            }
        }

        return $drift;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{key: string, label: string, detail?: array<string, mixed>}|null
     */
    private function detectBlocker(string $from, string $to, $sites, Server $server): ?array
    {
        if ($from === $to) {
            return [
                'key' => 'same_target',
                'label' => __('Already on :to.', ['to' => $to]),
            ];
        }
        if (! in_array($to, self::KNOWN_WEBSERVERS, true)) {
            return [
                'key' => 'unknown_target',
                'label' => __('Unknown webserver: :to.', ['to' => $to]),
            ];
        }

        // HTTP-front cache daemons (Varnish) own port :80 with the backend
        // webserver bound to 127.0.0.1:8080. The switch flow uses :8080 as
        // its staging port — both can't share it. Operator uninstalls
        // Varnish first, switches the webserver, then reinstalls Varnish.
        $varnishRow = $this->varnishRowFor($server);
        if ($varnishRow !== null) {
            return [
                'key' => 'varnish_running',
                'label' => __('Uninstall Varnish before switching the webserver — Varnish and the switch flow both need port :8080.'),
                'detail' => [
                    'engine' => 'varnish',
                    'status' => $varnishRow->status,
                ],
            ];
        }

        // Traefik (and HAProxy) are L7 edge proxies that don't serve PHP /
        // static content directly. dply installs Caddy as the per-site
        // backend (on ephemeral high ports) and routes through the edge
        // proxy at :80. All runtimes Caddy supports work through this path.

        return null;
    }

    /**
     * Load the server's sites once per instance with the relations every code
     * path here ultimately reads (config profile for drift, certificates for
     * the caddy TLS opt-in). Shared across all target evaluations.
     *
     * Delegates to the request-scoped {@see ServerWebserverSitesProvider} so the
     * drift detector and this preflight share a single sites + profiles +
     * certificates load on the webserver workspace render.
     *
     * @return Collection<int, Site>
     */
    private function sitesFor(Server $server): Collection
    {
        if (isset($this->sitesCache[$server->id])) {
            return $this->sitesCache[$server->id];
        }

        return $this->sitesCache[$server->id] = app(ServerWebserverSitesProvider::class)->for($server);
    }

    /**
     * Most-recent running/installing varnish row for this server, memoized so
     * the picker loop doesn't pay for a select per target.
     */
    private function varnishRowFor(Server $server): ?ServerCacheService
    {
        if (array_key_exists($server->id, $this->varnishCache)) {
            return $this->varnishCache[$server->id];
        }

        return $this->varnishCache[$server->id] = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', 'varnish')
            ->whereIn('status', [
                ServerCacheService::STATUS_RUNNING,
                ServerCacheService::STATUS_INSTALLING,
            ])
            ->first();
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function hasAnyTlsSites($sites): bool
    {
        // Conservative heuristic: any site with an issued certificate qualifies.
        // The job's TLS-handover step does the precise per-site dance.
        return $sites->contains(fn (Site $s) => $s->certificates->isNotEmpty());
    }

    /**
     * Computed downtime band, broken down by phase. The provisioning phase is
     * non-blocking (target webserver runs on :8080 alongside the current one);
     * only the cutover swap is a real availability blip.
     *
     * @return list<array{phase: string, label: string, estimate_ms: int, blocking: bool}>
     */
    private function downtimeBreakdown(int $sitesAffected, string $to): array
    {
        $installMs = match ($to) {
            'caddy' => 8_000,
            'apache' => 12_000,
            'openlitespeed' => 25_000,
            'traefik' => 6_000,
            default => 10_000,
        };
        // 5s per site is a coarse heuristic — config rebuild + remote write.
        $provisionMs = max(5_000, $sitesAffected * 5_000);
        $validateMs = 5_000;
        $cutoverMs = 600;

        return [
            ['phase' => 'install', 'label' => __('Install :to', ['to' => $to]), 'estimate_ms' => $installMs, 'blocking' => false],
            ['phase' => 'provision', 'label' => __('Provision :n site(s) on :8080', ['n' => $sitesAffected]), 'estimate_ms' => $provisionMs, 'blocking' => false],
            ['phase' => 'validate', 'label' => __('Validate'), 'estimate_ms' => $validateMs, 'blocking' => false],
            ['phase' => 'cutover', 'label' => __('Service-swap to :80'), 'estimate_ms' => $cutoverMs, 'blocking' => true],
        ];
    }

    /**
     * Convenience: is this `from → to` switch even possible end-to-end without
     * a blocker? Useful for disabling "Switch" CTAs in the picker grid before
     * the operator clicks.
     */
    public function isBlocked(Server $server, string $target): bool
    {
        return $this->plan($server, $target)['blocker'] !== null;
    }
}
