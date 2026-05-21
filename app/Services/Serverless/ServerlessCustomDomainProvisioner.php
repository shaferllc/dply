<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\Site;
use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provision DNS for an operator-owned hostname attached to a serverless
 * function — e.g. `api.acme.com → {site-edge-host}`.
 *
 * Two modes:
 *  - **auto**: dply's DigitalOcean token owns the hostname's apex zone.
 *    We write a CNAME from the hostname's relative name to the function's
 *    edge host (the existing testing-domain hostname or the app host).
 *    First purges any conflicting records at that name (CNAME exclusivity
 *    is enforced by DO and DNS itself).
 *  - **manual**: token doesn't own the zone. We persist the operator's
 *    intent + the exact CNAME target they need to create at their own DNS
 *    provider (Cloudflare / Route53 / etc.), then a separate verifyDomain
 *    call resolves DNS and flips dns_status from `pending` → `ready`.
 *
 * State is persisted into the entry inside
 * `site.meta.serverless.routing.custom_domains[*]` (matched by hostname).
 * The {@see ServerlessRoutingResolver} reads that state; the Livewire
 * page calls `provision()` on attach, `verify()` on demand, and
 * `remove()` on detach.
 */
final class ServerlessCustomDomainProvisioner
{
    public function __construct(private readonly ServerlessRoutingResolver $resolver) {}

    /**
     * Provision DNS for a hostname. Updates the matching custom-domain
     * entry on the site's meta and returns the post-update entry. Returns
     * null when the hostname isn't in the site's custom_domains list at all
     * (caller must add it first via the Livewire add action).
     *
     * @return array<string, mixed>|null
     */
    public function provision(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $edgeHost = $this->edgeHostFor($site);
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => '',
                'error' => 'No edge host configured yet — deploy the function first.',
            ]);
        }

        $token = trim((string) config('services.digitalocean.token'));
        $zone = $token !== '' ? $this->findOwnedZone($token, $hostname) : null;

        // Manual path: we don't own the zone, just persist the intent and
        // tell the operator what CNAME to create.
        if ($zone === null) {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'error' => null,
            ]);
        }

        // Auto path: we own the zone, write the CNAME.
        $recordName = (string) \Illuminate\Support\Str::beforeLast($hostname, '.'.$zone);
        if ($recordName === '') {
            $recordName = '@';
        }

        try {
            $do = new DigitalOceanService($token);
            $this->purgeConflictsAtName($do, $zone, $recordName);

            $target = rtrim($edgeHost, '.').'.';
            $existing = $do->findDomainRecord($zone, 'CNAME', $recordName, $target);
            if ($existing === null) {
                $do->createDomainRecord($zone, 'CNAME', $recordName, $target);
            }

            return $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'ready',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'record_name' => $recordName,
                'verified_at' => now()->toIso8601String(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Serverless custom-domain provisioning failed.', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);

            return $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'failed',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run a live DNS lookup to confirm a manually-configured hostname
     * resolves to dply's edge. Flips dns_status to `ready` on success.
     *
     * @return array<string, mixed>|null
     */
    public function verify(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $edgeHost = $this->edgeHostFor($site);
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'pending',
                'error' => 'No edge host configured yet — deploy the function first.',
            ]);
        }

        $records = @dns_get_record($hostname, DNS_CNAME | DNS_A);
        if (! is_array($records) || $records === []) {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'failed',
                'error' => "No DNS records found for {$hostname}. Make sure the CNAME is published and propagated.",
            ]);
        }

        $resolved = [];
        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'CNAME') {
                $resolved[] = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            } elseif ($type === 'A') {
                $resolved[] = (string) ($record['ip'] ?? '');
            }
        }
        $resolved = array_filter($resolved);
        $expected = strtolower(rtrim($edgeHost, '.'));
        $matches = in_array($expected, $resolved, true);

        return $this->updateEntry($site, $hostname, [
            'dns_status' => $matches ? 'ready' : 'failed',
            'cname_target' => $edgeHost,
            'verified_at' => now()->toIso8601String(),
            'error' => $matches
                ? null
                : "Hostname resolves to ".implode(', ', $resolved).", expected {$expected}.",
        ]);
    }

    /**
     * Remove a custom-domain entry from the site's meta. If we provisioned
     * it ourselves (mode=auto) and still own the zone, delete the DO
     * record too — best-effort, ignore failures.
     */
    public function remove(Site $site, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $removed = null;
        $remaining = [];
        foreach ($domains as $entry) {
            if (is_array($entry) && strtolower((string) ($entry['hostname'] ?? '')) === $hostname) {
                $removed = $entry;
                continue;
            }
            $remaining[] = $entry;
        }

        $routing['custom_domains'] = array_values($remaining);
        $serverless['routing'] = $routing;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        $this->resolver->invalidate($site);

        if ($removed !== null && ($removed['mode'] ?? null) === 'auto') {
            $zone = (string) ($removed['zone'] ?? '');
            $recordName = (string) ($removed['record_name'] ?? '');
            $token = trim((string) config('services.digitalocean.token'));
            if ($zone !== '' && $recordName !== '' && $token !== '') {
                try {
                    $do = new DigitalOceanService($token);
                    $record = $do->findDomainRecord($zone, 'CNAME', $recordName);
                    if ($record !== null && isset($record['id'])) {
                        $do->deleteDomainRecord($zone, (int) $record['id']);
                    }
                } catch (Throwable $e) {
                    Log::info('Serverless custom-domain DO record cleanup failed (non-fatal).', [
                        'site_id' => $site->id,
                        'hostname' => $hostname,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Persist a partial update to the named domain entry. Creates the
     * entry when absent (so first-time provision works). Always wipes the
     * resolver cache so the proxy sees fresh state.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function updateEntry(Site $site, string $hostname, array $patch): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $found = false;
        foreach ($domains as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (strtolower((string) ($entry['hostname'] ?? '')) === $hostname) {
                $domains[$i] = array_merge($entry, ['hostname' => $hostname], $patch);
                $found = true;
                break;
            }
        }
        if (! $found) {
            $domains[] = array_merge(['hostname' => $hostname], $patch);
        }

        $routing['custom_domains'] = array_values($domains);
        $serverless['routing'] = $routing;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        $this->resolver->invalidate($site);

        return collect($domains)
            ->first(fn (array $entry): bool => strtolower((string) ($entry['hostname'] ?? '')) === $hostname) ?? [];
    }

    /**
     * The host other DNS should point at to reach this function — the
     * auto-provisioned testing hostname when it exists, otherwise the
     * app's APP_URL host as a fallback. Empty when no edge URL is yet
     * resolvable (function not deployed).
     */
    private function edgeHostFor(Site $site): string
    {
        $functionHost = (string) ($site->serverlessFunctionHost() ?? '');
        if ($functionHost !== '') {
            return $functionHost;
        }

        $appUrl = (string) config('app.url');
        $host = parse_url($appUrl, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Find the longest matching DO zone the token owns for a hostname,
     * probing apex candidates derived from the hostname (most specific
     * first, then walk up). e.g. `api.staging.acme.com` →
     * `staging.acme.com` → `acme.com`. First hit wins. Avoids paginating
     * every domain in the account.
     */
    private function findOwnedZone(string $token, string $hostname): ?string
    {
        $do = new DigitalOceanService($token);
        $labels = explode('.', $hostname);

        // Walk left-to-right shrinking: start with the most-specific suffix
        // (one fewer label than the hostname itself, so e.g. `api.acme.com`
        // tries `acme.com` first, then `com`). Stop at TLD-only (2 labels).
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            try {
                if ($do->domainExistsInAccount($candidate)) {
                    return $candidate;
                }
            } catch (Throwable) {
                // Network blip — keep walking; an actual zone-missing returns false.
            }
        }

        // Also try the hostname itself as an apex (rare; supports literal-apex zones).
        try {
            if ($do->domainExistsInAccount($hostname)) {
                return $hostname;
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * CNAME exclusivity: a name carrying a CNAME cannot carry any other
     * record. Before writing our CNAME, drop any non-matching record at
     * the same name (mirrors {@see ServerlessFunctionDnsProvisioner}'s
     * purge logic, scoped to a single name).
     */
    private function purgeConflictsAtName(DigitalOceanService $do, string $zone, string $name): void
    {
        $records = $do->getDomainRecords($zone);
        $target = strtolower(trim($name));

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $recordName = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            if ($recordName !== $target) {
                continue;
            }
            // Writing CNAME → anything else at this name conflicts.
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > 0) {
                $do->deleteDomainRecord($zone, $recordId);
            }
        }
    }
}
