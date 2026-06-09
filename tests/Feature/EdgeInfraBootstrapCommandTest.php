<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeInfraBootstrapCommandTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('bootstrap dry run prints planned resources', function () {
    config([
        'edge.cloudflare.account_id' => 'acct123',
        'edge.cloudflare.api_token' => 'token123',
        'edge.r2.bucket' => '',
    ]);

    $this->artisan('dply:edge:infra:bootstrap', ['--dry-run' => true])
        ->expectsOutputToContain('DPLY_EDGE_R2_BUCKET=dply-edge-artifacts')
        ->expectsOutputToContain('DPLY_EDGE_CF_ACCOUNT_ID=acct123')
        ->assertSuccessful();
});

test('bootstrap creates bucket and kv namespace', function () {
    config([
        'edge.cloudflare.account_id' => 'acct123',
        'edge.cloudflare.api_token' => 'token123',
        'edge.cloudflare.kv_namespace_id' => '',
        'edge.cloudflare.cache_kv_namespace_id' => '',
        'edge.r2.bucket' => '',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();
        $method = $request->method();

        if (str_contains($url, '/tokens/verify')) {
            return Http::response(['success' => true, 'result' => ['status' => 'active']]);
        }
        if (str_contains($url, '/r2/buckets') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => ['buckets' => []]]);
        }
        if (str_contains($url, '/r2/buckets') && $method === 'POST') {
            return Http::response(['success' => true, 'result' => null]);
        }
        if (str_contains($url, '/storage/kv/namespaces') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => []]);
        }
        if (str_contains($url, '/storage/kv/namespaces') && $method === 'POST') {
            $body = json_decode($request->body(), true);
            $title = is_array($body) ? (string) ($body['title'] ?? '') : '';
            $id = $title === 'dply-edge-cache' ? 'cache-kv-id' : 'kv999';

            return Http::response(['success' => true, 'result' => ['id' => $id, 'title' => $title]]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$method.' '.$url]]], 500);
    });

    $this->artisan('dply:edge:infra:bootstrap', [
        '--bucket' => 'dply-edge-artifacts',
        '--kv-title' => 'dply-edge-host-map',
    ])
        ->expectsOutputToContain('Created R2 bucket')
        ->expectsOutputToContain('Created KV namespace: dply-edge-host-map (kv999)')
        ->expectsOutputToContain('Created cache KV namespace: dply-edge-cache (cache-kv-id)')
        ->expectsOutputToContain('DPLY_EDGE_CF_KV_NAMESPACE_ID=kv999')
        ->expectsOutputToContain('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID=cache-kv-id')
        ->assertSuccessful();
});

test('bootstrap fails without cloudflare credentials', function () {
    config([
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
    ]);

    $this->artisan('dply:edge:infra:bootstrap')
        ->assertFailed();
});
