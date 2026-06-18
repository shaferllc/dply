<?php

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Services\DigitalOceanService;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ensures a deployed serverless function's friendly hostname
 * ({slug}.{testing-domain}, e.g. laravel-demo.dply.host) has a DNS record
 * pointing at the dply app, which proxies the request through to the raw
 * DigitalOcean Functions invocation URL (DO Functions has no custom domains).
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
        $token = trim((string) config('services.digitalocean.token'));

        if ($zone === null || $recordName === '' || $token === '') {
            $this->store($site, [
                'status' => 'skipped',
                'hostname' => $host,
                'reason' => $token === '' ? 'missing_token' : 'unconfigured_zone',
            ]);

            return 'DNS: skipped — no app-level DigitalOcean token or testing zone.';
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
                $rt = strtoupper((string) ($r['type'] ?? ''));
                $rv = strtolower(rtrim((string) ($r['data'] ?? ''), '.'));
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
                    $recordId = (int) ($r['id']);
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
            if (! is_array($record)) {
                continue;
            }
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
            if (! is_array($record)) {
                continue;
            }
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
            if (! is_array($record)) {
                continue;
            }
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
        $domains = (array) config('services.digitalocean.testing_domains', []);
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * The function hostname points at the dply app, which proxies through to
     * DigitalOcean Functions. Defaults to a CNAME onto the testing-domain apex
     * (which must already resolve to the app);
     * DPLY_SERVERLESS_FUNCTION_DNS_TARGET overrides with an explicit IP
     * (A record) or hostname (CNAME).
     *
     * @return array{0: string, 1: string}
     */
    private function recordTarget(string $zone): array
    {
        $target = trim((string) config('services.digitalocean.serverless_function_dns_target'));
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
        $meta = ($site->meta );
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['dns'] = $payload;
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }
}
