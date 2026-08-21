<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Modules\Cache\Services\ServerlessCacheProvisioner;
use App\Support\Sites\SiteEnvFile;

/**
 * Prepares the `.env` a serverless function deploys with.
 *
 * dply manages a function's environment in `Site.env_file_content` — the
 * same encrypted column the Environment settings panel edits. A DigitalOcean
 * Functions action has no other channel for configuration, so this writes
 * that managed env into the build artifact at deploy time.
 *
 * Two guarantees make it Vapor-like:
 *  - First deploy seeds the managed env from whatever `.env` the repo ships,
 *    so the Environment panel starts populated from the app's own config.
 *  - A Laravel function always gets a stable `APP_KEY`: generated once,
 *    persisted, and reused on every later deploy and across cold instances.
 *    Without it the encrypter throws and Laravel cannot boot.
 */
class ServerlessEnvironmentPreparer
{
    /**
     * Required, deliberately not `?Foo $x = null`.
     *
     * The first version of this was nullable-with-a-default, on the theory that
     * it kept the class constructible without the Cache module. What it
     * actually did was hide a circular dependency: Deploy wanted Cache, Cache's
     * attach wanted Deploy, the container failed to resolve the chain, and
     * because a default was available it substituted `null` instead of
     * throwing. The auto-wiring compiled, passed a test that resolved the
     * provisioner directly, and would have silently done nothing in production.
     *
     * A required dependency cannot fail that way — it either resolves or the
     * container says so out loud. The cycle itself is gone: both sides now
     * share {@see SiteEnvFile} in the kernel rather than each other.
     */
    public function __construct(private readonly ServerlessCacheProvisioner $cacheProvisioner) {}


    /**
     * @param  bool  $isLaravel  whether the artifact builder detected Laravel
     * @return string a short log line describing what happened
     */
    public function prepare(Site $site, string $workingDirectory, bool $isLaravel): string
    {
        $repoEnvPath = rtrim($workingDirectory, '/').'/.env';
        $original = (string) $site->env_file_content;
        $managed = $original;
        $notes = [];

        // First deploy — seed dply's managed env from the repo's committed
        // .env so the Environment panel reflects the app's own defaults.
        if (trim($managed) === '' && is_file($repoEnvPath)) {
            $managed = (string) file_get_contents($repoEnvPath);
            if (trim($managed) !== '') {
                $notes[] = 'seeded environment from the repository .env';
            }
        }

        // Laravel needs a stable APP_KEY — generate once, persist, reuse.
        if ($isLaravel && ! $this->envHasKey($managed, 'APP_KEY')) {
            $managed = ltrim(rtrim($managed, "\r\n")."\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n", "\n");
            $notes[] = 'generated a managed APP_KEY';
        }

        // The command secret authenticates dply's background ticks
        // (scheduler / queue worker) against the deployed function. It is a
        // dedicated, dply-managed secret — minted once and stable — NOT the
        // operator-rotatable webhook_secret, so regenerating that secret can
        // never silently break the scheduler. Force-set on every deploy,
        // overriding any stale DPLY_COMMAND_SECRET committed in the repo .env.
        if ($isLaravel) {
            $managed = $this->setEnvKey($managed, 'DPLY_COMMAND_SECRET', $site->ensureServerlessCommandSecret());

            // DigitalOcean Functions invokes the action as Host: ccontroller.
            // Force APP_URL / ASSET_URL to the function's public hostname so
            // Vite and asset() never emit https://ccontroller/build/assets/….
            $publicUrl = $site->serverlessFriendlyUrl() ?: $site->serverlessPublicUrl();
            if (is_string($publicUrl) && $publicUrl !== '') {
                $managed = $this->setEnvKey($managed, 'APP_URL', $publicUrl);
                // ASSET_URL follows APP_URL unless deploy later publishes
                // `public/build` off-function and overwrites it.
                $managed = $this->setEnvKey($managed, 'ASSET_URL', $publicUrl);
            }

            if (! $this->envHasKey($managed, 'SESSION_DRIVER')) {
                $managed = $this->setEnvKey($managed, 'SESSION_DRIVER', 'cookie');
                $notes[] = 'defaulted SESSION_DRIVER to cookie';
            }

            // File log channels mkdir storage/logs. The function filesystem
            // is read-only except /tmp, so default to stderr (Monolog writes
            // to the platform log stream and never creates a directory).
            if (! $this->envHasKey($managed, 'LOG_CHANNEL')) {
                $managed = $this->setEnvKey($managed, 'LOG_CHANNEL', 'stderr');
                $notes[] = 'defaulted LOG_CHANNEL to stderr';
            }

            $managed = $this->setEnvKey(
                $managed,
                'DPLY_MAINTENANCE',
                $this->maintenanceEnabled($site) ? '1' : '0',
            );
        }

        // Log shipping — the function's handler POSTs each request it serves
        // to dply's ingest endpoint, HMAC-signed with the ingest secret. The
        // secret is always injected (stable, minted once); the URL only when
        // it is publicly reachable — a function on DigitalOcean cannot POST
        // to a local *.test / loopback APP_URL, so in dev we inject no URL
        // and the handler skips reporting cleanly.
        if ($isLaravel) {
            $managed = $this->setEnvKey($managed, 'DPLY_LOG_INGEST_SECRET', $this->logIngestSecret($site));
            $ingestUrl = $this->logIngestUrl($site);
            if ($ingestUrl !== '') {
                $managed = $this->setEnvKey($managed, 'DPLY_LOG_INGEST_URL', $ingestUrl);
            }
        }

        // Queue-pump wake. The handler pings this the moment the app queues a
        // job, so the pump starts draining within a round-trip instead of
        // waiting for the next one-minute safety-net tick. Authenticated with
        // the command secret injected above — no second secret to manage.
        // Same public-reachability rule as log ingest: no URL in dev, and the
        // handler skips the ping cleanly.
        if ($isLaravel) {
            $wakeUrl = $this->queueWakeUrl($site);
            if ($wakeUrl !== '') {
                $managed = $this->setEnvKey($managed, 'DPLY_QUEUE_WAKE_URL', $wakeUrl);
            }
        }

        // Persist so the value is stable and editable in the Environment panel.
        if ($managed !== $original) {
            $site->forceFill(['env_file_content' => $managed])->save();
        }

        /*
         * Give the function a shared cache if it has none.
         *
         * A function's default cache store is per-invocation, so
         * `ShouldBeUnique`, `WithoutOverlapping` and `RateLimited` silently do
         * nothing — the defect ServerlessQueueDoctorCommand reports. Wiring it
         * automatically is the point of the free tier: a customer should not
         * have to know the failure mode exists in order not to have it.
         *
         * Runs AFTER the save above, deliberately. The attach writes its own
         * keys through mergeKeys(), so doing it earlier would leave this
         * method holding a stale $managed and overwrite them a few lines
         * later. The env is re-read rather than merged in memory for the same
         * reason — the attach is the authority on what it wrote.
         *
         * See docs/adr/dply-cache.md, decision 3.
         */
        if ($isLaravel) {
            $wired = $this->cacheProvisioner->wire($site, SiteEnvFile::parse($managed));

            if ($wired !== []) {
                $managed = (string) ($site->fresh()?->env_file_content ?? $managed);
                $notes[] = 'attached a dply Cache';
            }
        }

        // Bundle the managed env into the artifact — Laravel's Dotenv reads it.
        if (trim($managed) !== '') {
            file_put_contents($repoEnvPath, $managed);
        }

        return $notes === []
            ? 'Using the managed environment.'
            : 'Environment: '.implode(', ', $notes).'.';
    }

    /**
     * Upsert keys into a function's managed environment (`env_file_content`)
     * — existing keys are replaced in place, new ones appended. Used to wire
     * a provisioned database's connection into the function.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeKeys(Site $site, array $values): void
    {
        $content = (string) $site->env_file_content;
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', $content) ?: []);

        foreach ($values as $key => $value) {
            $entry = $key.'='.$this->envValue((string) $value);
            $replaced = false;
            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', (string) $existing) === 1) {
                    $lines[$index] = $entry;
                    $replaced = true;
                    break;
                }
            }
            if (! $replaced) {
                $lines[] = $entry;
            }
        }

        $site->forceFill(['env_file_content' => implode("\n", $lines)])->save();
    }

    /**
     * Drop keys from a function's managed environment entirely.
     *
     * The counterpart to {@see mergeKeys()}, for detaching a resource. Setting
     * the value to an empty string instead would leave a dead `KEY=` line in
     * the Environment panel and, for a credential, keep the operator looking at
     * a variable that no longer means anything.
     *
     * @param  list<string>  $keys
     */
    public function forgetKeys(Site $site, array $keys): void
    {
        $content = (string) $site->env_file_content;
        if (trim($content) === '' || $keys === []) {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $kept = array_filter($lines, function ($line) use ($keys): bool {
            foreach ($keys as $key) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', (string) $line) === 1) {
                    return false;
                }
            }

            return true;
        });

        $site->forceFill(['env_file_content' => implode("\n", $kept)])->save();
    }

    /**
     * Point ASSET_URL at the published origin after `public/build` has been
     * uploaded (the attached disk's public URL, or the function hostname when
     * that disk is still the control plane). APP_URL stays the function hostname.
     */
    public function applyAssetUrl(Site $site, string $workingDirectory, string $assetUrl): void
    {
        $managed = $this->setEnvKey((string) $site->env_file_content, 'ASSET_URL', $assetUrl);
        if ($managed !== (string) $site->env_file_content) {
            $site->forceFill(['env_file_content' => $managed])->save();
        }

        $repoEnvPath = rtrim($workingDirectory, '/').'/.env';
        $current = is_file($repoEnvPath) ? (string) file_get_contents($repoEnvPath) : $managed;
        file_put_contents($repoEnvPath, $this->setEnvKey($current, 'ASSET_URL', $assetUrl));
    }

    /**
     * The site's stable log-ingest secret — minted once on the first deploy,
     * persisted in `meta.serverless.log_ingest_secret`, and reused after.
     * The ingest endpoint verifies the function's visit reports against it.
     */
    private function logIngestSecret(Site $site): string
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $secret = trim((string) ($serverless['log_ingest_secret'] ?? ''));

        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            $serverless['log_ingest_secret'] = $secret;
            $meta['serverless'] = $serverless;
            $site->forceFill(['meta' => $meta])->save();
        }

        return $secret;
    }

    /**
     * The URL the deployed function POSTs its per-request visit logs to —
     * or an empty string when reporting can't work and should be skipped.
     *
     * The function runs on DigitalOcean and must reach dply over the public
     * internet, so this is built from DPLY_PUBLIC_APP_URL — the operator's
     * public / tunnel URL that server-metrics callbacks already rely on.
     * APP_URL is no fallback: in development it is typically a local *.test
     * domain unreachable from DigitalOcean. Unset means no URL is injected
     * and the handler skips reporting.
     */
    private function logIngestUrl(Site $site): string
    {
        $public = trim((string) config('dply.public_app_url', ''));
        if ($public === '') {
            return '';
        }

        // DPLY_PUBLIC_APP_URL is often set to a bare hostname — add a scheme.
        if (preg_match('~^https?://~i', $public) !== 1) {
            $public = 'https://'.$public;
        }

        return rtrim($public, '/').'/hooks/functions/'.$site->id.'/log';
    }

    /**
     * The URL the deployed function pings when it queues a job, so dply's
     * pump can start draining immediately. Empty (and therefore skipped)
     * under the same conditions as {@see logIngestUrl()} — a function on
     * DigitalOcean cannot reach a local *.test address.
     */
    private function queueWakeUrl(Site $site): string
    {
        $ingest = $this->logIngestUrl($site);

        if ($ingest === '') {
            return '';
        }

        // Same host and reachability rules as ingest, different endpoint.
        return substr($ingest, 0, -strlen('/log')).'/queue/wake';
    }

    /**
     * Replace a key's line in a .env string, or append it when absent.
     */
    private function setEnvKey(string $env, string $key, string $value): string
    {
        $lines = $env === '' ? [] : (preg_split('/\r\n|\r|\n/', $env) ?: []);
        $entry = $key.'='.$this->envValue($value);
        $replaced = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', (string) $line) === 1) {
                $lines[$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $lines[] = $entry;
        }

        return implode("\n", $lines);
    }

    /**
     * Quote a .env value when it contains characters Dotenv would mishandle.
     */
    private function envValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/+=-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }

    private function envHasKey(string $env, string $key): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $env) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+)$/', $line, $matches) === 1
                && trim($matches[1], "\"' ") !== '') {
                return true;
            }
        }

        return false;
    }

    private function maintenanceEnabled(Site $site): bool
    {
        $maintenance = $site->serverlessConfig()['maintenance'] ?? false;

        if (is_array($maintenance)) {
            return (bool) ($maintenance['enabled'] ?? false);
        }

        return (bool) $maintenance;
    }
}
