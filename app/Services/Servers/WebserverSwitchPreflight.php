<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;

/**
 * Read-only previewer for the cascade triggered when an operator switches a
 * server's webserver (nginx → caddy / apache / openlitespeed / traefik).
 *
 * Returns a structured array the Livewire layer renders in the confirmation
 * modal. No side effects, no remote SSH.
 *
 * Drives the "Switch webserver" UX on `/servers/{srv}/manage/web`. Mirrors
 * the rename-cascade planner pattern from {@see \App\Services\Sites\PrimaryHostnameRenamePlanner}.
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
        'traefik',
    ];

    /**
     * Webservers that proxy to PHP-FPM via FastCGI. Sites on FPM stay put when
     * switching among these — the new webserver just rewires its upstream.
     *
     * @var list<string>
     */
    private const FPM_COMPATIBLE = ['nginx', 'caddy', 'apache'];

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
    public function plan(Server $server, string $target): array
    {
        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
        $to = strtolower(trim($target));

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->get(['id', 'name', 'runtime', 'status', 'type']);
        $sitesAffected = $sites->count();
        $driftSites = $this->detectCustomConfigDrift($sites);

        $blocker = $this->detectBlocker($from, $to, $sites);

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

        return [
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
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     * @return list<array{id: string, name: string, reason: string}>
     */
    private function detectCustomConfigDrift($sites): array
    {
        $profiles = SiteWebserverConfigProfile::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->get()
            ->keyBy('site_id');

        $drift = [];
        foreach ($sites as $site) {
            $profile = $profiles->get($site->id);
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
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     * @return array{key: string, label: string, detail?: array<string, mixed>}|null
     */
    private function detectBlocker(string $from, string $to, $sites): ?array
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

        $phpSites = $sites->filter(fn (Site $s) => $this->siteUsesPhp($s));
        $nonStaticOrNodeSites = $sites->filter(
            fn (Site $s) => ! in_array(strtolower((string) ($s->runtime ?? '')), ['static', 'node'], true)
        );

        if ($to === 'openlitespeed' && $phpSites->isNotEmpty()) {
            return [
                'key' => 'ols_needs_lsphp',
                'label' => __('OpenLiteSpeed needs `lsphpXX` packages — dply does not install these yet. :n PHP site(s) would 500 on every request.', ['n' => $phpSites->count()]),
                'detail' => ['site_ids' => $phpSites->pluck('id')->all()],
            ];
        }

        if ($to === 'traefik' && $nonStaticOrNodeSites->isNotEmpty()) {
            return [
                'key' => 'traefik_needs_static',
                'label' => __('Traefik is a reverse proxy and cannot serve PHP/Ruby directly. :n site(s) require an application-server upstream that dply does not configure yet.', ['n' => $nonStaticOrNodeSites->count()]),
                'detail' => ['site_ids' => $nonStaticOrNodeSites->pluck('id')->all()],
            ];
        }

        // Even when the runtime mix is compatible, OLS + Traefik per-site
        // config writing isn't wired in v1. The installers run cleanly; the
        // provisioning step would fail. Block here so operators see the
        // honest "coming soon" message instead of a half-completed switch.
        if (in_array($to, ['openlitespeed', 'traefik'], true)) {
            return [
                'key' => $to.'_provisioning_not_wired',
                'label' => __(':target installs cleanly in v1, but per-site config provisioning is on the v1.1 roadmap. The switch surface and installer are in place; site-runtime-aware vhost generation is the remaining piece.', ['target' => ucfirst($to)]),
            ];
        }

        return null;
    }

    private function siteUsesPhp(Site $site): bool
    {
        return strtolower((string) ($site->runtime ?? '')) === 'php';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     */
    private function hasAnyTlsSites($sites): bool
    {
        // Conservative heuristic: any site with an issued certificate qualifies.
        // The job's TLS-handover step does the precise per-site dance.
        return $sites->load('certificates')->contains(
            fn (Site $s) => $s->certificates->isNotEmpty()
        );
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
