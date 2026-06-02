<?php

declare(strict_types=1);

namespace App\Services\Snapshots;

use App\Models\Site;
use App\Models\Snapshot;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Aws\S3\S3Client;
use Illuminate\Support\Str;

/**
 * BYO S3-compatible bucket destination for snapshots (Q19 archive
 * product). Streams the dump from the server straight to S3 via a
 * presigned PUT URL, so:
 *   - Zero server-side AWS deps (just curl, which is universally
 *     installed). No need to push AWS credentials onto the server.
 *   - Zero dply-side memory cost — the dump bytes never transit
 *     dply at all; the server uploads directly to S3.
 *   - Works with any S3-compatible endpoint (DigitalOcean Spaces,
 *     Backblaze B2, Cloudflare R2, real S3, MinIO) by configuring
 *     `endpoint` + `region` on the S3Client constructor.
 *
 * Restore takes the inverse path: presigned GET URL streamed through
 * `gunzip | mysql` (or psql) on the server.
 *
 * Configuration via {@see config('snapshot_s3')} — single bucket per
 * dply install in v1; per-org buckets via the existing
 * ProviderCredential pattern lands in v2 once the bucket-picker UI
 * exists. The instance constructor lets tests inject a configured
 * S3Client for HTTP-faked end-to-end coverage.
 */
class S3Destination implements SnapshotDestination
{
    public const PRESIGNED_PUT_TTL_MINUTES = 30;

    public const PRESIGNED_GET_TTL_MINUTES = 30;

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly string $keyPrefix = '',
    ) {}

    public function persist(Site $site, string $reason, string $dumpRemotePath, int $bytes, string $engine, ?string $userId): Snapshot
    {
        $key = $this->buildObjectKey($site, $dumpRemotePath);

        $putRequest = $this->s3->createPresignedRequest(
            $this->s3->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => 'application/gzip',
            ]),
            '+'.self::PRESIGNED_PUT_TTL_MINUTES.' minutes',
        );
        $presignedUrl = (string) $putRequest->getUri();

        // The server streams the gzipped dump straight to S3 via curl
        // PUT. --fail-with-body so curl exits non-zero on 4xx/5xx.
        // `; rm -f` (not `&& rm -f`) so a failed upload still clears the
        // dump — it holds the full database — from /tmp; `( exit $rc )`
        // preserves curl's exit status for the check below.
        $uploadCmd = sprintf(
            'curl --silent --show-error --fail-with-body --request PUT --upload-file %s --header %s %s; __dply_rc=$?; rm -f %s; ( exit $__dply_rc )',
            escapeshellarg($dumpRemotePath),
            escapeshellarg('Content-Type: application/gzip'),
            escapeshellarg($presignedUrl),
            escapeshellarg($dumpRemotePath),
        );

        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'snapshot:s3-upload',
            inlineBash: $uploadCmd,
            timeoutSeconds: 1800,
        );

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException(
                'S3 presigned-PUT upload failed (exit '.var_export($out->getExitCode(), true).'): '.$out->getBuffer()
            );
        }

        return Snapshot::query()->create([
            'site_id' => $site->getKey(),
            'destination' => Snapshot::DESTINATION_S3,
            's3_bucket' => $this->bucket,
            's3_key' => $key,
            'local_path' => null,
            'bytes' => $bytes,
            'engine' => $engine,
            'reason' => $reason,
            'taken_by_user_id' => $userId,
            // S3 destination relies on the bucket's lifecycle rules
            // for retention; expires_at stays null so the local-disk
            // sweeper (which checks expires_at) doesn't think this is
            // a transient row.
            'expires_at' => null,
        ]);
    }

    public function restore(Snapshot $snapshot): void
    {
        if ($snapshot->s3_bucket === null || $snapshot->s3_key === null) {
            throw new \RuntimeException('Snapshot has no S3 location — cannot restore from S3.');
        }

        $getRequest = $this->s3->createPresignedRequest(
            $this->s3->getCommand('GetObject', [
                'Bucket' => $snapshot->s3_bucket,
                'Key' => $snapshot->s3_key,
            ]),
            '+'.self::PRESIGNED_GET_TTL_MINUTES.' minutes',
        );
        $presignedUrl = (string) $getRequest->getUri();

        // Stream the dump back through gunzip into the live DB. We use
        // --fail-with-body to surface 4xx/5xx as a non-zero exit,
        // | gunzip -c to decompress, then pipe to the right client
        // per engine.
        $restorePipe = match ($snapshot->engine) {
            'postgres', 'postgres17', 'postgres18' => '| gunzip -c | psql',
            default => '| gunzip -c | mysql',
        };

        $cmd = sprintf(
            'curl --silent --show-error --fail-with-body %s %s',
            escapeshellarg($presignedUrl),
            $restorePipe,
        );

        $out = $this->executor->runInlineBash(
            server: $snapshot->site->server,
            name: 'snapshot:s3-restore',
            inlineBash: $cmd,
            timeoutSeconds: 1800,
        );

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException(
                'S3 presigned-GET restore failed (exit '.var_export($out->getExitCode(), true).'): '.$out->getBuffer()
            );
        }
    }

    /**
     * S3 key shape: <prefix?>/<org-id>/<site-id>/<basename>.sql.gz
     * Org + site segments make manual bucket browsing sane and let
     * lifecycle rules target a single org's data when needed.
     */
    private function buildObjectKey(Site $site, string $dumpRemotePath): string
    {
        $orgSegment = $site->organization_id ?? 'no-org';
        $siteSegment = $site->getKey();
        $basename = basename($dumpRemotePath);
        // Add a randomness segment so two snapshots taken in the same
        // second don't collide on key — basename includes
        // /tmp/dply-snapshot-<slug>-<random>.sql.gz already, but be
        // defensive in case someone plumbs in a different upstream.
        if ($basename === '' || $basename === '.sql.gz') {
            $basename = 'snapshot-'.Str::random(8).'.sql.gz';
        }

        $segments = array_filter([
            trim($this->keyPrefix, '/'),
            (string) $orgSegment,
            (string) $siteSegment,
            $basename,
        ], fn ($s) => $s !== '');

        return implode('/', $segments);
    }
}
