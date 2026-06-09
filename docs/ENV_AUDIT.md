# .env audit — shrink the env surface

Prod `shared/.env` has **147 keys**. Most are legitimate (secrets + per-env), but
a chunk are either **stale**, **redundant framework scaffolding** (the `.env`
value equals the config default), or **constants** that don't need to be
env-tunable. Reducing them shrinks drift surface and the blast radius of the kind
of incident we just had.

Principle: **`.env` should hold only secrets and values that vary per
environment.** Everything else belongs in committed `config/*.php`.

## A. STALE — remove from prod `.env` now (safe)

Prod broadcasts via `pusher` (the CF relay). Reverb is retired, so every Reverb
key is dead on prod (keep them out of prod, but they stay valid in **local** dev
and in `config/broadcasting.php`):

```
REVERB_APP_ID  REVERB_APP_KEY  REVERB_APP_SECRET  REVERB_HOST
REVERB_PORT  REVERB_SCHEME  REVERB_SERVER_PORT
VITE_REVERB_HOST  VITE_REVERB_PORT  VITE_REVERB_SCHEME
```

> Bonus: `REVERB_HOST=https://dply.io` (scheme wrongly in the host) is exactly
> what produced the malformed `wss://https//dply.io:8080` Echo URL. Removing it
> eliminates the foot-gun.

## B. REDUNDANT framework scaffolding — drop from `.env` (config default is identical)

These are stock Laravel `.env.example` lines whose `config/*.php` default already
matches; deleting them changes nothing (verify each prod value equals the default
first — they're stock, so it's near-certain):

| Key | config default |
|-----|----------------|
| `APP_LOCALE` | `en` |
| `APP_FALLBACK_LOCALE` | `en` |
| `APP_FAKER_LOCALE` | `en_US` |
| `APP_MAINTENANCE_DRIVER` | `file` |
| `BCRYPT_ROUNDS` | `12` |
| `MEMCACHED_HOST` | `127.0.0.1` |
| `LOG_STACK` | `single` |
| `LOG_DEPRECATIONS_CHANNEL` | `null` |
| `SESSION_PATH` | `/` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` |

## C. CONSTANTS — bake into config, drop the `env()` (code change, review in git)

Non-secret tuning values that are the **same in every environment** and have no
reason to be operator-overridable. Replace `env('X', default)` with the literal
in the config file and delete the `.env` line. (Lower priority; only do these if
we're sure we never want to flip them per-box.)

- `DPLY_TESTING_DOMAIN_STRATEGY` → `'deterministic'` (constant today)
- `DPLY_EDGE_ORIGIN_HEALTHCHECK_{RETRIES,TIMEOUT,RETRY_WAIT_MS}` → fixed tuning
- `DPLY_EDGE_ARTIFACT_MAX_BYTES`, `DPLY_EDGE_BUILD_TIMEOUT` → fixed tuning
- `CLICKHOUSE_HTTP_PORT` (8123), `CLICKHOUSE_TIMEOUT`, `CLICKHOUSE_RETENTION_DAYS`

> NOT in this bucket: `FEATURE_*`, `DPLY_AUTO_TESTING_HOSTNAME_ENABLED`,
> `COMING_SOON*`, `HORIZON_*` — these are intentionally env-toggled per
> environment/box even though they look like constants. Leave them.

## D. KEEP — secrets + per-env (the bulk, ~110 keys)

`APP_KEY`, `APP_ENV/URL/DEBUG/NAME`, all `*_TOKEN/*_SECRET/*_KEY/*_PASSWORD`,
`DB_*`, `REDIS_*`, `MAIL_*`, `AWS_*` creds, `PUSHER_*` (relay), `DPLY_EDGE_CF_*` /
`R2_*` (account IDs/endpoints), `DPLY_TESTING_DOMAINS*`, OAuth redirect URIs,
`SESSION_DOMAIN`, `DPLY_LOG_DRAIN_*`, `CLICKHOUSE_*` creds, `STRIPE_*`,
`NAMECHEAP_*`, `DPLY_WORKER_ROLE`, queue/cache/session/filesystem drivers.

## Net effect

A+B removes ~17 keys with zero behavior change (≈12% of the file). C removes
another ~8 with a small, reviewable code change. The remaining ~120 are genuine
secrets/per-env values that must stay.

## Ready-to-run cleanup (A + B), operator-run, backed up

Removes only the stale + redundant keys; never touches a secret. Run per box:

```
for HOST in dply-app dply-worker-1; do
  ROOT=$([ "$HOST" = dply-app ] && echo /home/dply/dply || echo /home/dply/worker-1.dply.io)
  ssh "$HOST" "set -e; ENV=$ROOT/shared/.env; cp -a \$ENV \$ENV.bak.envtrim.\$(date +%s);
    sed -i -E '/^(REVERB_(APP_ID|APP_KEY|APP_SECRET|HOST|PORT|SCHEME|SERVER_PORT)|VITE_REVERB_(HOST|PORT|SCHEME)|APP_LOCALE|APP_FALLBACK_LOCALE|APP_FAKER_LOCALE|APP_MAINTENANCE_DRIVER|BCRYPT_ROUNDS|MEMCACHED_HOST|LOG_STACK|LOG_DEPRECATIONS_CHANNEL|SESSION_PATH|AWS_USE_PATH_STYLE_ENDPOINT)=/d' \$ENV;
    cd $ROOT/current && php artisan config:cache >/dev/null && php artisan route:cache >/dev/null && echo \"$HOST trimmed + recached\"";
done
```

(Then reload php-fpm on web / restart Horizon on the worker.) Spot-check the B
keys are stock defaults on prod before running.
