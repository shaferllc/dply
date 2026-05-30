<?php

declare(strict_types=1);

/**
 * Curated Caddy plugins for quick-add in the server workspace.
 * Full index: https://caddyserver.com/docs/modules
 *
 * @return array<string, array{label: string, description: string}>
 */
return [
    'catalog' => [
        'github.com/caddy-dns/cloudflare' => [
            'label' => 'Cloudflare DNS',
            'description' => 'DNS-01 ACME challenges via the Cloudflare API.',
        ],
        'github.com/caddy-dns/route53' => [
            'label' => 'Route53 DNS',
            'description' => 'DNS-01 ACME challenges via AWS Route53.',
        ],
        'github.com/caddy-dns/digitalocean' => [
            'label' => 'DigitalOcean DNS',
            'description' => 'DNS-01 ACME challenges via DigitalOcean DNS.',
        ],
        'github.com/mholt/caddy-l4' => [
            'label' => 'Layer 4 (L4)',
            'description' => 'TCP/UDP proxying and TLS termination at L4.',
        ],
        'github.com/caddyserver/transform-encoder' => [
            'label' => 'Transform encoder',
            'description' => 'Response body transforms (search/replace, regex).',
        ],
        'github.com/caddyserver/ntlm-transport' => [
            'label' => 'NTLM transport',
            'description' => 'HTTP transport with NTLM authentication.',
        ],
        'github.com/abiosoft/caddy-json-schema' => [
            'label' => 'JSON Schema validator',
            'description' => 'Validate JSON request/response bodies against a schema.',
        ],
    ],

    /** Max plugins in one custom build (xcaddy compiles can get slow). */
    'max_plugins' => 12,

    /** Remote SSH timeout for xcaddy rebuild jobs (seconds). */
    'rebuild_timeout_seconds' => 900,

    /** Public module registry used for browse + installed detection. */
    'registry_url' => env('CADDY_MODULES_REGISTRY_URL', 'https://caddyserver.com/api/modules'),

    /** Cache TTL for the registry index (seconds). */
    'registry_cache_seconds' => (int) env('CADDY_MODULES_REGISTRY_CACHE_SECONDS', 86_400),
];
