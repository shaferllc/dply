<?php

declare(strict_types=1);

/*
 * dply Logs — per-server host-level log shipping add-on ("dply Papertrail").
 *
 * An opt-in Vector agent runs on each managed box (systemd: dply-logship.service)
 * and ships host + service logs to a dply Vector aggregator, which authenticates,
 * stamps tenant identity, and bulk-inserts into ClickHouse. This config covers the
 * EDGE AGENT install layer (Phase 1); aggregator/ClickHouse/billing land later.
 *
 * See docs/SERVER_LOGS_ADDON.md for the full design + phasing.
 */
return [
    /**
     * Master kill-switch for the add-on. When false, the enable action and the
     * Install/Uninstall jobs no-op — lets us ship the code dark before the
     * aggregator/ClickHouse tier exists in an environment.
     */
    'enabled' => (bool) env('SERVER_LOGS_ENABLED', true),

    /**
     * Optional dedicated queue for log-agent install / uninstall jobs. Horizon
     * must list this queue (see config/horizon.php) for workers to pick it up;
     * the default queue is used when unset. Mirrors server_cache.install_queue.
     */
    'install_queue' => env('SERVER_LOGS_INSTALL_QUEUE', 'dply'),

    /**
     * Pinned Vector release installed on the box. Vector ships a single static
     * binary (no runtime deps), so install determinism is on us: bumping this
     * re-runs InstallLogAgentJob, which re-downloads + verifies the binary and
     * restarts the unit. Keep in lockstep with the aggregator's Vector version
     * to avoid protocol drift on the vector-to-vector link.
     */
    'vector_version' => env('SERVER_LOGS_VECTOR_VERSION', '0.48.0'),

    /**
     * SHA-256 of the pinned Vector linux amd64 tarball, verified after download
     * before we trust the binary. MUST be updated whenever vector_version is
     * bumped. Empty string disables the check (dev/fake-cloud only — never prod).
     */
    'vector_sha256' => env('SERVER_LOGS_VECTOR_SHA256', ''),

    /**
     * Where the edge agent ships logs. Points at the dply Vector aggregator's
     * mTLS endpoint (host:port). When empty, the rendered vector.toml uses a
     * `blackhole` sink instead — the agent still installs + runs healthily, which
     * is exactly what we want for fake-cloud / pre-aggregator testing.
     */
    'aggregator_endpoint' => env('SERVER_LOGS_AGGREGATOR_ENDPOINT', ''),

    /**
     * Port the dply Vector aggregator listens on for the vector-to-vector mTLS
     * link from edges. The codified installer ({@see \App\Jobs\InstallLogAggregatorJob})
     * stands the aggregator up on this port, opens it in UFW, and records the
     * resulting edge endpoint (server IP + this port) on the {@see \App\Models\ServerLogAggregator}
     * row — which then becomes the source of truth edges read from (config above is
     * the manual/legacy fallback).
     */
    'aggregator_listen_port' => (int) env('SERVER_LOGS_AGGREGATOR_PORT', 6000),

    /**
     * mTLS material the edge agent presents to the aggregator, deployed to
     * /etc/dply-logship/ during install. Base64-encoded PEM (keeps multi-line keys
     * out of .env). For the MVP this is a SHARED client cert across all edges;
     * per-server certs + cert→tenant mapping (closing the spoof gap) come later.
     *
     * Generate the base64 values from the aggregator box's /etc/dply-aggregator/tls/
     * (ca.crt, client.crt, client.key) — see /root/dply-logs-edge-mtls.env there.
     * When aggregator_endpoint is set, all three MUST be present or install fails.
     */
    'mtls' => [
        'ca_cert_b64' => env('SERVER_LOGS_CA_CERT_B64', ''),
        'client_cert_b64' => env('SERVER_LOGS_CLIENT_CERT_B64', ''),
        'client_key_b64' => env('SERVER_LOGS_CLIENT_KEY_B64', ''),
    ],

    /**
     * Log sources the agent can collect. `default => true` means ON unless the
     * customer toggles it off (cost control). `key` is the stable identifier
     * stored in server_log_agents.enabled_sources and referenced when rendering
     * vector.toml. Custom arbitrary paths are intentionally NOT here — they're a
     * Phase 2 fast-follow (unbounded-volume footgun).
     */
    'sources' => [
        'journald' => ['label' => 'System journal (systemd units)', 'default' => true],
        'web' => ['label' => 'Web server access + error (nginx/Caddy)', 'default' => true],
        'php_fpm' => ['label' => 'PHP-FPM', 'default' => true],
        'site_app' => ['label' => 'Per-site application logs', 'default' => true],
        'auth' => ['label' => 'auth.log (SSH / sudo / PAM)', 'default' => true],
    ],

    /**
     * On-box resource ceilings, enforced by the systemd unit. The agent runs on
     * the CUSTOMER'S box, so the app it monitors must always win: CPUQuota and
     * MemoryMax are hard caps; the disk buffer is bounded with drop-oldest so a
     * dply outage can never fill their disk (we lose oldest buffered logs, not
     * their server).
     */
    'limits' => [
        'cpu_quota_percent' => (int) env('SERVER_LOGS_CPU_QUOTA', 15),
        'memory_max' => env('SERVER_LOGS_MEMORY_MAX', '128M'),
        'disk_buffer_max_bytes' => (int) env('SERVER_LOGS_DISK_BUFFER_BYTES', 512 * 1024 * 1024),
    ],

    /**
     * Best-effort secret/PII redaction applied at the edge (Vector VRL) BEFORE
     * logs leave the box — the strongest place to scrub. These are coarse
     * patterns; a richer ruleset can come later. `redact_ips` is opt-in because
     * IPs are load-bearing for security/abuse investigations.
     */
    'redaction' => [
        'enabled' => (bool) env('SERVER_LOGS_REDACTION', true),
        'redact_ips' => (bool) env('SERVER_LOGS_REDACT_IPS', false),
    ],

    /**
     * Per-org entitlements for the paid add-on (docs/SERVER_LOGS_BILLING.md §1.2).
     * `defaults` is the free MVP baseline EVERY org gets — it MUST match the
     * pre-billing behaviour (7-day retention, add-on available, no overage) so
     * turning this on changes nothing for current users. `plans` overrides
     * individual keys per subscription-plan key (see config('subscription.standard.plans'):
     * free/starter/pro/business). Resolved by {@see \App\Modules\Logs\Services\ServerLogEntitlements}.
     *
     * The volume/retention numbers are uncalibrated placeholders — the doc's
     * "Open quantities" stay unset until Phase 1 dogfooding produces real bytes/day.
     * `overage_per_gb_cents` is 0 everywhere until PR C flips billing on; these are
     * just the dials PR C reads. The global `enabled` kill-switch above still gates
     * the whole add-on per environment; this layers per-org availability on top.
     */
    'entitlements' => [
        'defaults' => [
            'available' => true,            // may the org enable the add-on at all
            'retention_days' => (int) env('SERVER_LOGS_DEFAULT_RETENTION_DAYS', 7),
            'monthly_included_gb' => 1,
            'overage_per_gb_cents' => 0,    // 0 = no overage billing yet (PR C)
            'max_servers' => null,          // null = unlimited shipping servers
            'alerting_enabled' => false,
            'drains_enabled' => false,
            'hard_cap_gb' => 0,             // 0 = no ingest cap (fail open; PR C2)
        ],
        'plans' => [
            'pro' => [
                'retention_days' => 30,
                'monthly_included_gb' => 10,
                'alerting_enabled' => true,
                'drains_enabled' => true,
            ],
            'business' => [
                'retention_days' => 90,
                'monthly_included_gb' => 50,
                'alerting_enabled' => true,
                'drains_enabled' => true,
            ],
        ],
    ],

    /**
     * Billing master switch (docs/SERVER_LOGS_BILLING.md §1.3 / PR C). When OFF
     * the usage cost calculator returns 0 regardless of metered volume, so the
     * full metering → estimate → Stripe path can land dark and be exercised in
     * prod without charging anyone. Even when ON, an org is only billed if its
     * plan carries a non-zero `entitlements.*.overage_per_gb_cents` (all 0 today)
     * AND a `subscription.standard.stripe.server_log_usage` price id is set.
     * Flip on only after dogfooding calibrates real bytes/day against cost.
     */
    'billing' => [
        'enabled' => (bool) env('SERVER_LOGS_BILLING_ENABLED', false),
    ],

    /**
     * Deploy user the agent's config/state is owned by; the binary + unit are
     * installed system-wide as root. Mirrors server_provision.deploy_ssh_user.
     */
    'deploy_user' => env('DPLY_DEPLOY_SSH_USER', 'dply'),

    /**
     * ClickHouse — the log store. The Vector aggregator bulk-inserts here; Laravel
     * only READS (the log explorer) and runs the one-time DDL via `dply:logs:schema-sync`.
     * Never in the ingest hot path. Local dev: `docker compose -f docker-compose.clickhouse.yml up -d`
     * gives you a ClickHouse on 127.0.0.1:8123. Prod points at managed ClickHouse
     * (ClickHouse Cloud / Altinity / Aiven). See docs/SERVER_LOGS_ADDON.md.
     */
    'clickhouse' => [
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'http_port' => 8123,
        'scheme' => env('CLICKHOUSE_SCHEME', 'http'), // 'https' for managed
        'database' => env('CLICKHOUSE_DATABASE', 'dply_logs'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'table' => env('CLICKHOUSE_LOGS_TABLE', 'server_logs'),
        'timeout' => 15,

        /**
         * TLS verification for https connections. When the endpoint uses a cert
         * signed by our private CA (cross-provider prod → DO over public internet,
         * via the nginx TLS proxy), set CLICKHOUSE_CA_CERT_B64 to the base64 CA so
         * the client verifies against it. As a last resort CLICKHOUSE_TLS_VERIFY=false
         * disables verification (still encrypted; only for IP-locked endpoints).
         */
        'verify' => true,
        'ca_cert_b64' => env('CLICKHOUSE_CA_CERT_B64', ''),

        /**
         * Default retention applied as the table TTL by the schema-sync command.
         * Per-tier retention (Phase 2 billing) will override per partition later;
         * this is the floor every box gets in the free MVP.
         */
        'retention_days' => 7,
    ],
];
