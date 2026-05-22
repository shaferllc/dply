<?php

namespace App\Services\ConfigRevisions;

use App\Models\ConfigRevision;

/**
 * Storage layer for revisions of remote files/configs that Dply manages.
 * The recorder is intentionally dumb: it stores snapshots and dedupes by
 * checksum. Each caller (PHP editor, webserver editor, future supervisor
 * editor, ...) is responsible for what the snapshot looks like and how
 * to load it back into its own editor.
 */
class ConfigRevisionRecorder
{
    /**
     * Capture a snapshot for `streamKey`. Returns the new revision, or
     * `null` if the snapshot is byte-identical to the most recent revision
     * in this stream (dedup — no point recording an identical row).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function capture(
        string $streamKey,
        string $kind,
        array $snapshot,
        ConfigRevisionContext $context,
    ): ?ConfigRevision {
        $checksum = $this->checksumFor($snapshot);

        $latest = ConfigRevision::query()->forStream($streamKey)->first();
        if ($latest !== null && $latest->checksum === $checksum) {
            return null;
        }

        return ConfigRevision::query()->create([
            'stream_key' => $streamKey,
            'server_id' => $context->server?->getKey(),
            'subject_type' => $context->subject?->getMorphClass(),
            'subject_id' => $context->subject?->getKey(),
            'kind' => $kind,
            'user_id' => $context->user?->getKey(),
            'summary' => $context->summary,
            'snapshot' => $snapshot,
            'checksum' => $checksum,
        ]);
    }

    /**
     * The checksum that the recorder stores. Public so callers can do
     * drift detection: hash live content the same way and compare to
     * the latest revision's `checksum` column.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function checksumFor(array $snapshot): string
    {
        return hash('sha256', $this->canonicalize($snapshot));
    }

    /**
     * Canonical JSON encoding: sorted keys, no whitespace, slashes
     * unescaped, unicode preserved. Required so semantically-identical
     * snapshots produce identical checksums regardless of key order in
     * the caller's array literal.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function canonicalize(array $snapshot): string
    {
        $sorted = $this->sortKeysRecursively($snapshot);

        return (string) json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function sortKeysRecursively(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->sortKeysRecursively($v);
            }
        }

        return $value;
    }
}
