# Hybrid Edge — streaming support matrix

The Worker proxies dynamic routes (those listed in `meta.edge.origin.routes`)
to the configured origin URL. This page documents which streaming /
long-lived response patterns pass through unchanged and which need extra
work on the origin side.

## What works without changes

| Pattern | Notes |
|---|---|
| Server-Sent Events (`text/event-stream`) | `upstream.body` is a `ReadableStream`; the Worker passes it through end-to-end. Origin should set `Cache-Control: no-store` and avoid `Content-Length`. |
| Chunked HTTP responses | Same as SSE — any response with no fixed `Content-Length` streams through. |
| WebSockets (`Upgrade: websocket`) | The Worker detects the upgrade header and routes through `proxyWebSocket()`, which forwards the handshake to the origin and pipes both directions. The origin **must** accept the upgrade with a 101 response. The auth secret (`X-Dply-Origin-Auth`) is attached to the upgrade request. |
| Large response bodies | Streamed — the Worker does not buffer the whole body. |
| Range requests | Origin handles `Range` headers normally; Worker only sets origin auth headers, it does not rewrite range. |

## What does not work (or needs origin cooperation)

| Pattern | What to do |
|---|---|
| Long-poll over plain HTTP for > Worker request timeout | Cloudflare caps Worker subrequests at ~30 s of CPU time. Use SSE or WebSocket instead. |
| Origin push without an outbound request | Cloudflare Workers cannot push; client must initiate. WebSocket is the supported "server-to-client realtime" path. |
| HTTP/2 server push (`Link: rel=preload; nopush`) | Not supported by Workers fetch. Replace with `<link rel="preload">` in HTML or early-hints (Cloudflare-native, separate feature). |
| Trailers (HTTP/2 trailers) | Not surfaced by Worker fetch — strip on the origin. |
| Origin auth secret in WebSocket subprotocol | We attach `X-Dply-Origin-Auth` to the upgrade request, not the `Sec-WebSocket-Protocol` header. Origin should verify the header during upgrade, not after. |

## Auto-retry interaction

The Worker auto-retries one time on a 5xx or network error from the
origin (for idempotent methods only). **SSE and WebSocket connections
are NOT retried** — the runtime hands off the stream as soon as the
upstream handshake succeeds, so a mid-stream failure surfaces to the
client. Origins should make their initial handshake reliable.

## Failover page interaction

When both retry attempts fail (or the only attempt for a non-idempotent
request), the Worker serves `meta.edge.origin.failover_html` (or a
built-in default) as HTTP 503 with `Retry-After: 30`. Failover is
HTML-only — there is no streaming or WebSocket failover behavior.

## Testing your origin

```sh
# SSE smoke test
curl -N https://my-site.example/api/events

# WebSocket smoke test
websocat wss://my-site.example/realtime

# Confirm auth header reaches origin
# (origin should reject without it)
curl https://my-origin.dply.app/api/anything   # → expect 401/403
curl https://my-site.example/api/anything      # → expect 200 (Worker injects header)
```
