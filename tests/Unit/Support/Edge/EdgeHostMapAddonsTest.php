<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeHostMapAddons;

test('managed edge site addons flatten into host map fields', function () {
    config([
        'edge.log_ingest.key' => 'test-ingest-key',
        'edge.log_ingest.base_url' => 'https://app.example.test',
    ]);

    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'turnstile' => [
                    'enabled' => true,
                    'site_key' => 'site',
                    'secret_key' => 'secret',
                    'mode' => 'forms',
                ],
                'rate_limit' => [
                    'enabled' => true,
                    'rules' => [
                        ['path' => '/api/*', 'limit' => 30, 'window_seconds' => 60, 'action' => 'challenge'],
                    ],
                ],
                'forms' => [
                    'enabled' => true,
                    'endpoints' => [
                        ['path' => '/contact', 'to_email' => 'ops@example.com', 'honeypot' => 'company', 'require_turnstile' => true],
                    ],
                ],
                'waiting_room' => [
                    'enabled' => true,
                    'total_active_users' => 50,
                    'new_users_per_minute' => 5,
                    'session_duration_minutes' => 15,
                    'paths' => ['/*'],
                ],
                'snippets' => [
                    'enabled' => true,
                    'items' => [
                        ['name' => 'pixel', 'phase' => 'body', 'path' => '/*', 'html' => '<!-- x -->'],
                    ],
                ],
                'tags' => [
                    'enabled' => true,
                    'consent_required' => true,
                    'tools' => [
                        ['name' => 'ga', 'src' => 'https://www.googletagmanager.com/gtag/js', 'async' => true],
                    ],
                ],
                'jobs' => [
                    'enabled' => true,
                    'default_queue' => 'JOBS',
                ],
            ],
        ],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $payload = EdgeHostMapAddons::payload($site);

    expect($payload['turnstile']['site_key'])->toBe('site')
        ->and($payload['rate_limit']['rules'][0]['action'])->toBe('challenge')
        ->and($payload['forms']['ingest_url'])->toContain('/hooks/edge/')
        ->and($payload['forms']['ingest_url'])->toContain('/forms')
        ->and($payload['waiting_room']['total_active_users'])->toBe(50)
        ->and($payload['snippets']['items'][0]['html'])->toBe('<!-- x -->')
        ->and($payload['tags']['tools'][0]['src'])->toStartWith('https://')
        ->and($payload['jobs']['default_queue'])->toBe('JOBS');
});

test('byo cloudflare sites do not receive addon host map fields', function () {
    $site = new Site([
        'edge_backend' => 'org_cloudflare',
        'meta' => [
            'edge' => [
                'turnstile' => ['enabled' => true, 'site_key' => 'x', 'secret_key' => 'y', 'mode' => 'all'],
            ],
        ],
    ]);

    expect(EdgeHostMapAddons::payload($site))->toBe([]);
});
