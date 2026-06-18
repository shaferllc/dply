<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoadmapItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            // In progress — product lines actively shipping
            [
                'title' => 'dply Edge static delivery',
                'summary' => 'Git-connected JavaScript and static sites with previews, CDN, and deploy hooks.',
                'description' => 'First-party Edge hosting on on-dply.site with build → R2 → Worker delivery, preview protection, and usage analytics.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'edge',
                'sort_order' => 0,
                'is_published' => true,
            ],
            [
                'title' => 'dply Cloud container apps',
                'summary' => 'Managed PHP and Rails on DigitalOcean App Platform and AWS App Runner.',
                'description' => 'Long-running container hosting with cost-plus billing, attached databases, and hybrid SSR origin for Edge sites.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'cloud',
                'sort_order' => 1,
                'is_published' => true,
            ],
            [
                'title' => 'Serverless functions',
                'summary' => 'Deploy functions to DO Functions, Cloudflare Workers, and more.',
                'description' => 'Org-scoped serverless sites with provider adapters, Laravel on OpenWhisk, and optional dply-managed backend billing.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'serverless',
                'sort_order' => 2,
                'is_published' => true,
            ],
            [
                'title' => 'Browser-based server Console',
                'summary' => 'Audited in-browser SSH sessions with RBAC.',
                'description' => 'Server workspace Console tab for time-boxed shell access without leaving the app — gated behind workspace.console.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'servers',
                'sort_order' => 3,
                'is_published' => true,
            ],
            [
                'title' => 'Server Insights',
                'summary' => 'Actionable capacity and hygiene suggestions per server.',
                'description' => 'Findings runners surface problems and suggestions — buffer pool sizing, release dir sprawl, and similar ops nudges.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'servers',
                'sort_order' => 4,
                'is_published' => true,
            ],
            [
                'title' => 'Server-scoped Backups',
                'summary' => 'Database and site-file backup runs and schedules.',
                'description' => 'On-server backup storage with optional org S3 destinations — server workspace Backups tab.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'servers',
                'sort_order' => 5,
                'is_published' => true,
            ],
            [
                'title' => 'Caddy webserver engine',
                'summary' => 'Full Caddy workspace with modules, custom routes, and admin API.',
                'description' => 'Change tab, config editor integration, and drift detection — currently in webserver_coming_soon alongside Apache and OLS.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'servers',
                'sort_order' => 6,
                'is_published' => true,
            ],
            [
                'title' => 'Edge proxy add-on',
                'summary' => 'Optional Traefik, HAProxy, or Envoy in front of site webservers.',
                'description' => 'L7 reverse proxy layer on BYO VMs — client → edge proxy :80 → Caddy on high ports → app.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'servers',
                'sort_order' => 7,
                'is_published' => true,
            ],
            [
                'title' => 'Fleet operations hub',
                'summary' => 'Cross-server health, deploys, domains, and env search.',
                'description' => 'Org-wide Fleet surface — Health, Deploys, Previews, Domains, Env, Intelligence, and Deploy contracts.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'platform',
                'sort_order' => 8,
                'is_published' => true,
            ],
            [
                'title' => 'Deploy Pipeline workspace',
                'summary' => 'Visual BYO deploy steps, hooks, and pre-deploy advisor.',
                'description' => 'Per-site pipeline builder with DnD steps, clone/activate anchors, issue fixes, and named templates.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'platform',
                'sort_order' => 9,
                'is_published' => true,
            ],
            [
                'title' => '.dply.yaml repo sync',
                'summary' => 'Apply redirects, crons, hooks, and env declarations from the repo on deploy.',
                'description' => 'Post-deploy sync of repo-driven BYO config — global.byo_repo_config.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'platform',
                'sort_order' => 10,
                'is_published' => true,
            ],
            [
                'title' => 'Deploy contract gates',
                'summary' => 'Block Edge promotes until health, env, and review checks pass.',
                'description' => 'DeployContractEvaluator runs policy checks with waiver audit — global.deploy_contract.',
                'status' => RoadmapItem::STATUS_IN_PROGRESS,
                'area' => 'edge',
                'sort_order' => 11,
                'is_published' => true,
            ],

            // Shipped — already live capabilities worth highlighting
            [
                'title' => 'dply CLI device login',
                'summary' => 'Browser auth, interactive shell, and site deploy from terminal.',
                'description' => 'Device-flow login, dply site deploy --follow, and .dply/site.json link workflow.',
                'status' => RoadmapItem::STATUS_SHIPPED,
                'area' => 'platform',
                'sort_order' => 0,
                'is_published' => true,
                'shipped_at' => '2026-05-01',
            ],

            // Planned — gated or preview-only today
            [
                'title' => 'Runbook Marketplace',
                'summary' => 'Import curated automation recipes into your org.',
                'description' => 'Browse marketplace runbooks, scripts, and workspace recipes — surface.marketplace product line.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 0,
                'is_published' => true,
            ],
            [
                'title' => 'Public status pages',
                'summary' => 'Customer-facing uptime and incident pages per org.',
                'description' => 'Managed status pages with monitors, incidents, and public /status/{slug} URLs.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 1,
                'is_published' => true,
            ],
            [
                'title' => 'dply-managed servers',
                'summary' => 'Hetzner VMs on dply\'s platform token, billed all-in.',
                'description' => 'Skip provider credentials — dply provisions and operates the VM with cost-plus metering.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 2,
                'is_published' => true,
            ],
            [
                'title' => 'Remote Files workspace',
                'summary' => 'Browse and edit allowlisted server files from the app.',
                'description' => 'SSH-backed file catalog with short-TTL cache — security-reviewed write path.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 3,
                'is_published' => true,
            ],
            [
                'title' => 'Saved Run commands',
                'summary' => 'Org-wide saved shell commands and ad-hoc Run tab.',
                'description' => 'Server workspace Run surface for audited remote command execution.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 4,
                'is_published' => true,
            ],
            [
                'title' => 'Server Blueprint capture',
                'summary' => 'Snapshot a server stack and apply on create.',
                'description' => 'Capture mise runtimes, webserver, and site defaults — replay via create wizard.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 5,
                'is_published' => true,
            ],
            [
                'title' => 'Release hygiene scanner',
                'summary' => 'Find stale release dirs and prune safely on atomic deploys.',
                'description' => 'Server workspace Release hygiene tab with templates for Laravel and generic stacks.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 6,
                'is_published' => true,
            ],
            [
                'title' => 'SSH security digest',
                'summary' => 'auth.log scan summaries and SSH hardening nudges.',
                'description' => 'Periodic digest of suspicious SSH activity per server — workspace.security_digest.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 7,
                'is_published' => true,
            ],
            [
                'title' => 'MariaDB database engine',
                'summary' => 'Install and manage MariaDB alongside MySQL family tools.',
                'description' => 'Full Databases workspace sub-tabs — currently Soon badge until database.mariadb flag rolls out.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 8,
                'is_published' => true,
            ],
            [
                'title' => 'Valkey cache engine',
                'summary' => 'Redis-compatible Valkey install on BYO servers.',
                'description' => 'Caches workspace engine with install guard — cache.valkey Pennant rollout.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 9,
                'is_published' => true,
            ],
            [
                'title' => 'Per-site CDN and cache purging',
                'summary' => 'Cloudflare CDN attach, purge, and edge cache zones.',
                'description' => 'Site workspace CDN + engine cache subtabs — workspace.site_cdn and site_caching.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 10,
                'is_published' => true,
            ],
            [
                'title' => 'Hosted WordPress',
                'summary' => 'Managed WordPress on dply-controlled infrastructure.',
                'description' => 'Hosted-only WordPress product line — not customer VMs or SSH in v1.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 11,
                'is_published' => true,
            ],
            [
                'title' => 'Full-stack launch wizard',
                'summary' => 'Plan Edge + Cloud + BYO from one architecture flow.',
                'description' => 'FullStackArchitecturePlanner handoffs with query prefills — launch.full_stack_wizard.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 12,
                'is_published' => true,
            ],
            [
                'title' => 'Ops Copilot',
                'summary' => 'Fleet-wide deploy failure triage and suggested fixes.',
                'description' => 'LLM + heuristic ops assistant across BYO and Edge failures — global.ops_copilot.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 13,
                'is_published' => true,
            ],
            [
                'title' => 'Cost observatory',
                'summary' => 'Provider infra plus dply platform spend in one view.',
                'description' => 'OrganizationCostObservatory on Billing analytics — BYO provider estimates + managed product usage.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'platform',
                'sort_order' => 14,
                'is_published' => true,
            ],
            [
                'title' => 'Preview review hub',
                'summary' => 'Threaded preview comments and optional promote gate.',
                'description' => 'Edge preview review approvals, PR links, and promote blocking until review-ready.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'edge',
                'sort_order' => 12,
                'is_published' => true,
            ],
            [
                'title' => 'Edge SSR hybrid origin',
                'summary' => 'Worker static delivery with Cloud or external origin fetch.',
                'description' => 'Hybrid Edge stack auto-provisions Cloud origin when SSR is detected — Phase 4 SSR path.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'edge',
                'sort_order' => 13,
                'is_published' => true,
            ],
            [
                'title' => 'Ephemeral deploy credentials',
                'summary' => 'Per-deploy SSH keys provisioned and revoked automatically.',
                'description' => 'Ed25519 keys scoped to a single deploy via EphemeralDeployCredentialManager.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 12,
                'is_published' => true,
            ],
            [
                'title' => 'Deploy windows',
                'summary' => 'Allow or block deploys by schedule on servers and sites.',
                'description' => 'Deploy window policies integrated with RunSiteDeploymentJob — GA on the server Deploys page (Deploy windows tab).',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 13,
                'is_published' => true,
            ],
            [
                'title' => 'Server maintenance mode',
                'summary' => 'Suspend visitors with a branded static page until resumed.',
                'description' => 'Server-wide or per-site maintenance — workspace.server_maintenance.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 14,
                'is_published' => true,
            ],
            [
                'title' => 'SSH access graph',
                'summary' => 'See who can reach which servers and grant time-boxed sessions.',
                'description' => 'Access graph plus contractor SSH sessions with auto-revoke — workspace.ssh_access_graph.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 15,
                'is_published' => true,
            ],
            [
                'title' => 'Health cockpit',
                'summary' => 'Capacity, releases, and reliability tabs per server.',
                'description' => 'Server Health workspace with overview, capacity, releases, and reliability views.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 16,
                'is_published' => true,
            ],
            [
                'title' => 'Shared Host Radar',
                'summary' => 'Attribute load and stack pressure across sites on one VM.',
                'description' => 'Multi-site load attribution and soft budgets — workspace.shared_host.',
                'status' => RoadmapItem::STATUS_PLANNED,
                'area' => 'servers',
                'sort_order' => 17,
                'is_published' => true,
            ],
        ];

        foreach ($items as $item) {
            RoadmapItem::query()->updateOrCreate(
                ['title' => $item['title']],
                $item,
            );
        }

        $this->assignReleaseTrains();
    }

    private function assignReleaseTrains(): void
    {
        $trains = RoadmapRelease::query()->pluck('id', 'slug');

        $targets = [
            'dply Edge static delivery' => '2026-06',
            'dply Cloud container apps' => '2026-06',
            'Serverless functions' => '2026-06',
            'Deploy contract gates' => '2026-06',
            'Browser-based server Console' => '2026-07',
            'Server Insights' => '2026-07',
            'Server-scoped Backups' => '2026-07',
            'Caddy webserver engine' => '2026-07',
            'Edge proxy add-on' => '2026-07',
            'Fleet operations hub' => '2026-05',
            'Deploy Pipeline workspace' => '2026-05',
            '.dply.yaml repo sync' => '2026-05',
            'Runbook Marketplace' => '2026-09',
            'Public status pages' => '2026-09',
            'dply-managed servers' => '2026-09',
            'Full-stack launch wizard' => '2026-09',
            'Ops Copilot' => '2026-09',
            'Cost observatory' => '2026-09',
            'Preview review hub' => '2026-09',
            'Edge SSR hybrid origin' => '2026-12',
            'Ephemeral deploy credentials' => '2026-07',
            'Deploy windows' => '2026-07',
            'Server maintenance mode' => '2026-07',
            'SSH access graph' => '2026-07',
            'Health cockpit' => '2026-07',
            'Shared Host Radar' => '2026-07',
            'Hosted WordPress' => '2026-12',
        ];

        $shippedTargets = [
            'dply CLI device login' => '2026-05',
        ];

        foreach ($targets as $title => $slug) {
            if (! isset($trains[$slug])) {
                continue;
            }

            RoadmapItem::query()->where('title', $title)->update([
                'target_release_id' => $trains[$slug],
                'target_quarter' => match ($slug) {
                    '2026-05', '2026-06', '2026-07' => '2026-Q2',
                    '2026-09' => '2026-Q3',
                    '2026-12' => '2026-Q4',
                    default => null,
                },
            ]);
        }

        foreach ($shippedTargets as $title => $slug) {
            if (! isset($trains[$slug])) {
                continue;
            }

            RoadmapItem::query()->where('title', $title)->update([
                'shipped_release_id' => $trains[$slug],
            ]);
        }
    }
}
