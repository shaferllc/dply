<?php

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Cloud\Cloudflare\CloudflareDnsService;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ensures a deployed serverless function's friendly hostname
 * ({slug}.dply-serverless.cloud) has a DNS record pointing at the dply app,
 * which proxies the request through to the raw DigitalOcean Functions
 * invocation URL (DO Functions has no custom domains).
 *
 * Two DNS backends, chosen per zone by
 * {@see ServerlessTestingDomains::dnsProviderForZone()}: the serverless apex is
 * a Cloudflare zone, while hostnames minted on the legacy shared
 * DPLY_TESTING_DOMAINS pool live on DigitalOcean and keep the DO path so they
 * stay repairable.
 *
 * Idempotent: every deploy/redeploy calls this, but the record is only
 * created when it is missing. DNS failures never fail the deploy — the
 * function is already live at its raw URL and the /fn/{slug} path proxy.
 */
final class ServerlessFunctionDnsProvisioner
{
    /**
     * Provision (if missing) the function's hostname record. Returns a short
     * human-readable status line for the deploy log, or null when there is
     * nothing to do (no testing domains configured).
     */
    public function provision(Site $site): ?string
    {
        $host = $site->serverlessFunctionHost();
        if ($host === null) {
            return null;
        }

        $zone = $this->zoneForHost($host);
        $recordName = $zone !== null ? (string) Str::beforeLast($host, '.'.$zone) : '';

        if ($zone === null || $recordName === '') {
            $this->store($site, [
                'status' => 'skipped',
                'hostname' => $host,
                'reason' => 'unconfigured_zone',
            ]);

            return 'DNS: skipped — '.$host.' is not on a configured serverless apex.';
        }

        // Wildcard mode: a hand-created `*.{apex}` record already answers for
        // every function, so there is nothing to create and no credential to
        // need. Record the hostname as live and make no API call.
        if (ServerlessTestingDomains::usesWildcard($zone)) {
            $this->store($site, [
                'status' => 'ready',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_type' => 'CNAME',
                'record_data' => '*.'.$zone,
                'covered_by_wildcard' => true,
                'dns_provider' => 'wildcard',
                'provisioned_at' => now()->toIso8601String(),
            ]);

            return 'DNS: covered by *.'.$zone.' — no record needed.';
        }

        // The serverless apex (dply-serverless.cloud) is a Cloudflare zone;
        // legacy pool hostnames stay on the DigitalOcean path.
        if (ServerlessTestingDomains::dnsProviderForZone($zone) === 'cloudflare') {
            return $this->provisionViaCloudflare($site, $host, $zone, $recordName);
        }

        return $this->provisionViaDigitalOcean($site, $host, $zone, $recordName);
    }

    /**
     * Write the hostname record into the Cloudflare zone. Proxied through
     * Cloudflare (the client sets `proxied: true`), so the apex gets edge TLS
     * for `*.{apex}` from Universal SSL without dply issuing a wildcard cert.
     */
    private function provisionViaCloudflare(Site $site, string $host, string $zone, string $recordName): string
    {
        $token = ServerlessTestingDomains::cloudflareApiToken();

        if ($token === '') {
            $this->store($site, [
                'status' => 'skipped',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'dns_provider' => 'cloudflare',
                'reason' => 'missing_token',
            ]);

            return 'DNS: skipped — no Cloudflare API token for '.$zone.'.';
        }

        [$type, $value] = $this->recordTarget($zone);

        try {
            $cloudflare = new CloudflareDnsService($token);

            if (! $cloudflare->zoneExists($zone)) {
                $this->store($site, [
                    'status' => 'skipped',
                    'hostname' => $host,
                    'zone' => $zone,
                    'record_name' => $recordName,
                    'dns_provider' => 'cloudflare',
                    'reason' => 'unconfigured_zone',
                ]);

                return 'DNS: skipped — '.$zone.' is not a zone this Cloudflare token can reach.';
            }

            // A `*.{apex}` record already answers for every function hostname,
            // which is the recommended production setup — writing a specific
            // record on top of it is redundant.
            $wildcard = $this->cloudflareWildcardCovering($cloudflare, $zone);
            if ($wildcard !== null) {
                $this->store($site, [
                    'status' => 'ready',
                    'hostname' => $host,
                    'zone' => $zone,
                    'record_name' => $recordName,
                    'record_type' => strtoupper((string) ($wildcard['type'] ?? '')),
                    'record_data' => (string) ($wildcard['content'] ?? ''),
                    'covered_by_wildcard' => true,
                    'wildcard_record_id' => $wildcard['id'] ?? null,
                    'dns_provider' => 'cloudflare',
                    'provisioned_at' => now()->toIso8601String(),
                ]);

                return 'DNS: covered by *.'.$zone.' '.($wildcard['type'] ?? '').' '.($wildcard['content'] ?? '');
            }

            // Cloudflare rejects a CNAME sharing a name with any other record
            // and vice versa, but its own upsert helpers already find-and-update
            // the matching row, so there is no DO-style purge dance here.
            $record = strtoupper($type) === 'A'
                ? $cloudflare->upsertARecord($zone, $recordName, $value)
                : $cloudflare->upsertCnameRecord($zone, $recordName, rtrim($value, '.'));

            $this->store($site, [
                'status' => 'ready',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_id' => $record['id'] ?? null,
                'record_type' => strtoupper($type),
                'record_data' => rtrim($value, '.'),
                'dns_provider' => 'cloudflare',
                'provisioned_at' => now()->toIso8601String(),
            ]);

            return 'DNS: '.$host.' → '.strtoupper($type).' '.rtrim($value, '.').' (Cloudflare)';
        } catch (\Throwable $e) {
            Log::warning('Serverless function DNS provisioning failed (Cloudflare).', [
                'site_id' => $site->id,
                'hostname' => $host,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);

            $this->store($site, [
                'status' => 'failed',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'dns_provider' => 'cloudflare',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            return 'DNS: failed — '.$e->getMessage();
        }
    }

    /**
     * The `*.{zone}` A or CNAME record, when one exists.
     *
     * @return array<string, mixed>|null
     */
    private function cloudflareWildcardCovering(CloudflareDnsService $cloudflare, string $zone): ?array
    {
        foreach (['CNAME', 'A', 'AAAA'] as $type) {
            $records = $cloudflare->listDnsRecords($zone, $type, '*.'.$zone);
            foreach ($records as $record) {
                if (trim((string) ($record['content'] ?? '')) !== '') {
                    return $record;
                }
            }
        }

        return null;
    }

    private function provisionViaDigitalOcean(Site $site, string $host, string $zone, string $recordName): string
    {
        $token = trim((string) config('services.digitalocean.token'));

        if ($token === '') {
            $this->store($site, [
                'status' => 'skipped',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'dns_provider' => 'digitalocean',
                'reason' => 'missing_token',
            ]);

            return 'DNS: skipped — no app-level DigitalOcean token.';
        }

        [$type, $value] = $this->recordTarget($zone);

        try {
            // Short-circuit if a wildcard `*` record in the zone already
            // covers this name. Most setups in this category run an A or
            // CNAME wildcard pointed at dply's edge; in that case writing a
            // specific record is both redundant AND conflicts with DO's
            // CNAME-exclusivity check at the wildcard's name namespace.
            $wildcard = $this->wildcardCovering($token, $zone);
            if ($wildcard !== null) {
                $this->store($site, [
                    'status' => 'ready',
                    'hostname' => $host,
                    'zone' => $zone,
                    'record_name' => $recordName,
                    'record_type' => strtoupper((string) ($wildcard['type'] ?? '')),
                    'record_data' => (string) ($wildcard['data'] ?? ''),
                    'covered_by_wildcard' => true,
                    'wildcard_record_id' => $wildcard['id'] ?? null,
                    'provisioned_at' => now()->toIso8601String(),
                ]);

                return 'DNS: covered by *.'.$zone.' '.($wildcard['type'] ?? '').' '.($wildcard['data'] ?? '');
            }

            // DNS allows at most one record-shape per name when CNAME is
            // involved (a name carrying a CNAME cannot also carry any other
            // record, and a CNAME cannot share a name with an A/AAAA/MX/etc.).
            // Clear any conflicting records left over from prior attempts or
            // manual edits before writing the one we want. The target value
            // lets us preserve an exact match so the upsert can no-op rather
            // than delete+recreate.
            $this->purgeConflictingRecords($token, $zone, $recordName, $type, $value);

            // Verify the purge actually cleared the conflict. DO occasionally
            // serves stale list-records output for a moment after a delete;
            // when the post-purge list still shows conflicts, delete them
            // directly by ID rather than logging and letting the create fail.
            $stillThere = $this->dumpRecordsAtName($token, $zone, $recordName);
            $blocking = array_values(array_filter($stillThere, function (array $r) use ($type, $value): bool {
                $rt = strtoupper((string) $r['type']);
                $rv = strtolower(rtrim((string) $r['data'], '.'));
                $sameTargetMatch = $rt === strtoupper($type) && $rv === strtolower(rtrim($value, '.'));
                if ($sameTargetMatch) {
                    return false; // exact match — upsert will no-op on this row
                }

                // Anything else at the name blocks a CNAME create.
                return strtoupper($type) === 'CNAME' || $rt === 'CNAME';
            }));
            if ($blocking !== []) {
                Log::warning('Serverless DNS: conflicts remain after purge — force-deleting by ID.', [
                    'zone' => $zone,
                    'record_name' => $recordName,
                    'still_present' => $blocking,
                ]);
                $do = new DigitalOceanService($token);
                foreach ($blocking as $r) {
                    $recordId = (int) $r['id'];
                    if ($recordId > 0) {
                        $do->deleteDomainRecord($zone, $recordId);
                    }
                }
            }

            $record = SiteDnsProviderFactory::forDigitalOceanAppConfigToken($token)
                ->upsertRecord($zone, $type, $recordName, $value);

            $this->store($site, [
                'status' => 'ready',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_id' => $record['id'] ?? null,
                'record_type' => $type,
                'record_data' => $value,
                'provisioned_at' => now()->toIso8601String(),
            ]);

            return 'DNS: '.$host.' → '.$type.' '.$value;
        } catch (\Throwable $e) {
            // Capture the records that are actually at this name so the
            // operator can see what's blocking the create, even if our purge
            // matcher missed them. Skip on best-effort — if listing fails too,
            // we still want to surface the original create error.
            $conflictDump = [];
            try {
                $conflictDump = $this->dumpRecordsAtName($token, $zone, $recordName);
            } catch (\Throwable) {
                // Swallow: the create-error is the real story; the dump is decoration.
            }

            Log::warning('Serverless function DNS provisioning failed.', [
                'site_id' => $site->id,
                'hostname' => $host,
                'error' => $e->getMessage(),
                'records_at_name' => $conflictDump,
            ]);

            $this->store($site, [
                'status' => 'failed',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'error' => $e->getMessage(),
                'records_at_name' => $conflictDump,
                'failed_at' => now()->toIso8601String(),
            ]);

            return 'DNS: failed — '.$e->getMessage();
        }
    }

    /**
     * Delete records at $name that can't coexist with the one we're about to
     * write:
     *  - When writing CNAME → delete every record at $name (CNAME exclusivity)
     *  - Writing anything else → delete any CNAME at $name (same rule from the
     *    other direction), but leave parallel records of the target type alone
     *    so existing round-robin A pools aren't trashed.
     *
     * Same-type, same-value records are left alone so `upsertRecord` can find
     * them and treat the provisioning as a no-op.
     */
    private function purgeConflictingRecords(string $token, string $zone, string $name, string $writingType, string $writingValue): void
    {
        $writingType = strtoupper($writingType);
        $writingValueNormalized = strtolower(rtrim(trim($writingValue), '.'));
        $do = new DigitalOceanService($token);

        // List all records in the zone (don't rely on DO's `name` query
        // filter — its behavior varies, and we need to match defensively
        // against trailing-dot / case / FQDN-vs-relative normalization).
        $records = $do->getDomainRecords($zone);

        // The same record-name can be stored either as a relative label
        // (`laravel-demo`) or as the full FQDN (`laravel-demo.dply.host`,
        // sometimes with a trailing dot). Normalize both sides so any of
        // those forms collide as expected.
        $targets = [
            strtolower(trim($name)),
            strtolower(rtrim($name.'.'.$zone, '.')),
        ];

        foreach ($records as $record) {
            $recordName = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            $existingType = strtoupper((string) ($record['type'] ?? ''));
            if ($recordName === '' || $existingType === '') {
                continue;
            }
            if (! in_array($recordName, $targets, true)) {
                continue;
            }

            // Rule set for what conflicts with the record we're about to write:
            //  - Writing CNAME → name must be empty of everything else (CNAME
            //    exclusivity). That includes other CNAMEs pointing elsewhere —
            //    only one CNAME per name is allowed.
            //  - Writing A/AAAA/etc. → only CNAME at that name conflicts (other
            //    A records at the same name are fine for round-robin).
            //  - Same type AND same value as what we want to write → not a
            //    conflict; leave the record alone so the upsert is a no-op.
            $existingValue = strtolower(rtrim((string) ($record['data'] ?? ''), '.'));
            $matchesTarget = $existingType === $writingType && $existingValue === $writingValueNormalized;
            if ($matchesTarget) {
                continue;
            }

            $shouldDelete = $writingType === 'CNAME'
                ? true // CNAME must be alone — kill everything else at this name
                : $existingType === 'CNAME'; // Writing non-CNAME only conflicts with CNAME

            if (! $shouldDelete) {
                continue;
            }

            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > 0) {
                Log::info('Serverless DNS: purging conflicting record before upsert.', [
                    'zone' => $zone,
                    'record_id' => $recordId,
                    'record_name' => $record['name'] ?? null,
                    'record_type' => $existingType,
                    'record_data' => $record['data'] ?? null,
                    'writing_type' => $writingType,
                    'writing_value' => $writingValue,
                ]);
                $do->deleteDomainRecord($zone, $recordId);
            }
        }
    }

    /**
     * If the zone has a `*` wildcard record (A / AAAA / CNAME) that already
     * resolves arbitrary subdomains to dply's edge, return it. Callers can
     * skip writing a specific record and treat the function's hostname as
     * "already provisioned" via the wildcard.
     *
     * We accept any wildcard pointing at SOMETHING (A/AAAA → IP, CNAME →
     * any target) because the dply edge is the only thing this zone is for
     * in the testing-domain configuration. If you ever multi-tenant the
     * zone, narrow this match to known edge targets.
     *
     * @return array<string, mixed>|null
     */
    private function wildcardCovering(string $token, string $zone): ?array
    {
        $do = new DigitalOceanService($token);
        $records = $do->getDomainRecords($zone);
        foreach ($records as $record) {
            $name = trim((string) ($record['name'] ?? ''));
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($name !== '*') {
                continue;
            }
            if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Return a compact dump of every record at the target name. Surfaces
     * what's actually in DO when the create fails, so the operator (and we)
     * can spot the conflict our purge matcher didn't reach.
     *
     * @return list<array{id: int, type: string, name: string, data: string}>
     */
    private function dumpRecordsAtName(string $token, string $zone, string $name): array
    {
        $do = new DigitalOceanService($token);
        $records = $do->getDomainRecords($zone);

        $targets = [
            strtolower(trim($name)),
            strtolower(rtrim($name.'.'.$zone, '.')),
        ];

        $out = [];
        foreach ($records as $record) {
            $recordName = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            if (! in_array($recordName, $targets, true)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($record['id'] ?? 0),
                'type' => strtoupper((string) ($record['type'] ?? '')),
                'name' => (string) ($record['name'] ?? ''),
                'data' => (string) ($record['data'] ?? ''),
            ];
        }

        return $out;
    }

    private function zoneForHost(string $host): ?string
    {
        return ServerlessTestingDomains::zoneForHost($host);
    }

    /**
     * The function hostname points at the dply app, which proxies through to
     * DigitalOcean Functions. Defaults to a CNAME onto the serverless apex
     * (which must already resolve to the app);
     * DPLY_SERVERLESS_TESTING_DNS_TARGET (or the legacy
     * DPLY_SERVERLESS_FUNCTION_DNS_TARGET) overrides with an explicit IP
     * (A record) or hostname (CNAME).
     *
     * @return array{0: string, 1: string}
     */
    private function recordTarget(string $zone): array
    {
        // `config($key, $default)` returns null for a key that exists and IS
        // null, so the legacy fallback has to be an explicit `??`.
        $target = trim((string) (
            config('serverless.testing_dns_target')
            ?? config('services.digitalocean.serverless_function_dns_target')
        ));
        if ($target === '') {
            return ['CNAME', rtrim($zone, '.').'.'];
        }

        return filter_var($target, FILTER_VALIDATE_IP) !== false
            ? ['A', $target]
            : ['CNAME', rtrim($target, '.').'.'];
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function store(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['dns'] = $payload;
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }
}
