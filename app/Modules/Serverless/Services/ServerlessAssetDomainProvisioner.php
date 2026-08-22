<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Attaches a customer's own hostname (e.g. `cdn.acme.com`) to a function's
 * published assets.
 *
 * ## Why the origin is the site's own asset hostname
 *
 * Assets are routed by a single fleet-wide Cloudflare rule that derives the
 * bucket prefix from the request's Host header ({@see ServerlessAssetHost}).
 * An arbitrary customer domain carries no label that rule can read, and no
 * static rule can derive one.
 *
 * So the Cloudflare-for-SaaS custom hostname points its `custom_origin_server`
 * at *this site's own* default asset hostname. The origin fetch re-enters the
 * zone on a host the fleet-wide rule already matches, and resolves normally.
 * That is what keeps custom domains unlimited: no rule per hostname (which
 * Cloudflare's ruleset limits would cap) and no Worker on the hot path.
 *
 * `EdgeCloudflareClient::createCustomHostname()` already merges caller options
 * over the configured fallback origin, so this needs no change there — it just
 * passes a per-site origin instead of relying on the global one.
 *
 * ## Only ACTIVE hostnames become billable
 *
 * A site's ASSET_URL moves to the custom hostname on its next deploy, so while
 * a hostname is still validating, the DEFAULT hostname is the one actually
 * serving. Billing treats "has a custom hostname" as "bill the custom one and
 * ignore the default as an internal hop"
 * ({@see ServerlessAssetEgressReader}), so promoting a hostname too early
 * would drop real traffic from the meter. Pending hostnames therefore live in
 * the details map only, and are promoted into `custom_hostnames` on
 * verification.
 */
class ServerlessAssetDomainProvisioner
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public function __construct(private ?EdgeCloudflareClient $cloudflare = null) {}

    /**
     * Attach a hostname and start certificate validation.
     *
     * @return array<string, mixed> the stored entry for this hostname
     */
    public function attach(Site $site, string $hostname): array
    {
        $hostname = $this->normalize($hostname);
        $origin = ServerlessAssetHost::hostname($site);

        if ($origin === null) {
            throw new RuntimeException('Deploy the function once before attaching an asset domain.');
        }

        if ($hostname === $origin) {
            throw new RuntimeException('That is already this function\'s asset hostname.');
        }

        $client = $this->client();
        $zoneId = $this->zoneIdFor($site, $client);

        $remote = $client->createCustomHostname($zoneId, $hostname, [
            // The whole mechanism: send the origin fetch back through a host
            // the fleet-wide routing rule can read a prefix from.
            'custom_origin_server' => $origin,
        ]);

        $entry = [
            'hostname' => $hostname,
            'cf_custom_hostname_id' => (string) (data_get($remote, 'result.id') ?? ''),
            'origin' => $origin,
            'status' => $this->statusFrom($remote),
            'attached_at' => now()->toIso8601String(),
            'error' => null,
        ];

        $this->writeEntry($site, $entry);

        Log::info('serverless.assets.domain_attached', [
            'site_id' => $site->id,
            'hostname' => $hostname,
            'origin' => $origin,
        ]);

        return $entry;
    }

    /**
     * Re-read validation state from Cloudflare and promote the hostname into
     * the billable set once it goes active.
     *
     * @return array<string, mixed>|null the updated entry, or null when the
     *                                   hostname is not attached to this site
     */
    public function verify(Site $site, string $hostname): ?array
    {
        $hostname = $this->normalize($hostname);
        $entry = $this->entry($site, $hostname);
        if ($entry === null) {
            return null;
        }

        $customHostnameId = (string) ($entry['cf_custom_hostname_id'] ?? '');
        if ($customHostnameId === '') {
            return $entry;
        }

        try {
            $remote = $this->client()->getCustomHostname($this->zoneIdFor($site, $this->client()), $customHostnameId);
            $entry['status'] = $this->statusFrom($remote);
            $entry['error'] = null;
        } catch (Throwable $e) {
            // Leave the previous status intact — a transient API failure is
            // not evidence the hostname stopped working.
            $entry['error'] = $e->getMessage();
        }

        $entry['verified_at'] = now()->toIso8601String();
        $this->writeEntry($site, $entry);

        return $entry;
    }

    /**
     * Detach a hostname: remove it at Cloudflare and from the site.
     */
    public function detach(Site $site, string $hostname): void
    {
        $hostname = $this->normalize($hostname);
        $entry = $this->entry($site, $hostname);

        $customHostnameId = is_array($entry) ? (string) ($entry['cf_custom_hostname_id'] ?? '') : '';
        if ($customHostnameId !== '') {
            try {
                $this->client()->deleteCustomHostname($this->zoneIdFor($site, $this->client()), $customHostnameId);
            } catch (Throwable $e) {
                // Non-fatal: the local record must still go, or the UI would
                // show a domain the operator cannot remove.
                Log::info('serverless.assets.domain_delete_failed', [
                    'site_id' => $site->id,
                    'hostname' => $hostname,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->writeEntry($site, null, $hostname);
    }

    /**
     * Every attached hostname with its state, whatever its status.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(Site $site): array
    {
        $assets = $site->serverlessConfig()['assets'] ?? [];
        $details = is_array($assets) ? ($assets['custom_hostname_details'] ?? []) : [];

        if (! is_array($details)) {
            return [];
        }

        return array_values(array_filter($details, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(Site $site, string $hostname): ?array
    {
        foreach ($this->entries($site) as $entry) {
            if (($entry['hostname'] ?? null) === $hostname) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Upsert (or, with $removeHostname, delete) an entry and re-derive the
     * billable hostname list from it.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function writeEntry(Site $site, ?array $entry, ?string $removeHostname = null): void
    {
        $hostname = $removeHostname ?? (string) ($entry['hostname'] ?? '');
        if ($hostname === '') {
            return;
        }

        $details = [];
        foreach ($this->entries($site) as $existing) {
            if (($existing['hostname'] ?? null) !== $hostname) {
                $details[] = $existing;
            }
        }

        if ($entry !== null && $removeHostname === null) {
            $details[] = $entry;
        }

        // Only active hostnames are customer-facing, so only they belong in
        // the list metering reads. See the class docblock.
        $active = [];
        foreach ($details as $row) {
            if (($row['status'] ?? null) === self::STATUS_ACTIVE) {
                $active[] = (string) $row['hostname'];
            }
        }

        $meta = $site->meta ?? [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $assets = is_array($serverless['assets'] ?? null) ? $serverless['assets'] : [];

        $assets['custom_hostname_details'] = $details;
        $assets['custom_hostnames'] = array_values(array_unique($active));

        $serverless['assets'] = $assets;
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function statusFrom(array $remote): string
    {
        $status = strtolower(trim((string) (data_get($remote, 'result.status') ?? '')));

        return match ($status) {
            'active' => self::STATUS_ACTIVE,
            'deleted', 'blocked', 'moved' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
    }

    private function zoneIdFor(Site $site, EdgeCloudflareClient $client): string
    {
        $zoneName = ServerlessTestingDomains::apexFor($site->getKey());
        $zoneId = $client->activeZoneId($zoneName);

        if ($zoneId === null) {
            throw new RuntimeException('Could not resolve the Cloudflare zone for '.$zoneName.'.');
        }

        return $zoneId;
    }

    private function normalize(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = (string) preg_replace('~^https?://~', '', $hostname);
        $hostname = rtrim(explode('/', $hostname)[0], '.');

        if ($hostname === '' || ! str_contains($hostname, '.')) {
            throw new RuntimeException('Enter a valid hostname, e.g. cdn.example.com.');
        }

        return $hostname;
    }

    private function client(): EdgeCloudflareClient
    {
        return $this->cloudflare ??= EdgeCloudflareClient::fromConfig();
    }
}
