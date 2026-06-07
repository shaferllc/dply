<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves the browser-side Laravel Echo connection from the *active* broadcast
 * connection so the control-plane UI works against whichever relay is wired:
 *
 *   - local dev  → `reverb` (php artisan reverb:start, ws://*.test:8080)
 *   - production → `pusher` pointed at the dply managed realtime Worker
 *     (packages/realtime-worker, wss://realtime.on-dply.site:443) — the exact
 *     same path customers get from a managed broadcasting binding.
 *
 * Read at render time (not baked into Vite) so a `.env` + `config:cache` flip
 * is enough to cut over — no frontend rebuild. Returns null when broadcasting
 * is disabled (null/log) or the active connection has no client wiring, in
 * which case the layout skips the Echo module entirely and the UI falls back
 * to polling.
 */
class EchoClientConfig
{
    /**
     * @return array{driver:string,key:string,host:?string,port:int,scheme:string,enabled:bool,bypass_local_guard:bool}|null
     */
    public static function forBrowser(): ?array
    {
        $connection = (string) config('broadcasting.default');

        return match ($connection) {
            'pusher' => self::fromPusher(),
            'reverb' => self::fromReverb(),
            default => null,
        };
    }

    /**
     * @return array{driver:string,key:string,host:?string,port:int,scheme:string,enabled:bool,bypass_local_guard:bool}|null
     */
    private static function fromPusher(): ?array
    {
        $key = (string) config('broadcasting.connections.pusher.key');
        if ($key === '') {
            return null;
        }

        $options = (array) config('broadcasting.connections.pusher.options', []);
        $scheme = strtolower((string) ($options['scheme'] ?? 'https'));
        $host = trim((string) ($options['host'] ?? ''));

        return [
            'driver' => 'pusher',
            'key' => $key,
            // A relay host is required for pusher-on-dply; the api-*.pusher.com
            // fallback only makes sense for real Pusher Channels, which the
            // control plane never uses, so treat a blank host as "no host".
            'host' => $host !== '' ? $host : null,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $scheme === 'https' ? 'https' : 'http',
            'enabled' => (bool) config('broadcasting.echo_client_enabled', true),
            'bypass_local_guard' => (bool) config('broadcasting.reverb_bypass_local_guard', false),
        ];
    }

    /**
     * @return array{driver:string,key:string,host:?string,port:int,scheme:string,enabled:bool,bypass_local_guard:bool}|null
     */
    private static function fromReverb(): ?array
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        if ($key === '') {
            return null;
        }

        $options = (array) config('broadcasting.connections.reverb.options', []);
        $scheme = strtolower((string) ($options['scheme'] ?? 'http'));
        $host = $options['host'] ?? null;

        return [
            'driver' => 'reverb',
            'key' => $key,
            'host' => filled($host) ? (string) $host : null,
            'port' => (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 8080)),
            'scheme' => $scheme === 'https' ? 'https' : 'http',
            'enabled' => (bool) config('broadcasting.echo_client_enabled', true),
            'bypass_local_guard' => (bool) config('broadcasting.reverb_bypass_local_guard', false),
        ];
    }
}
