<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConfigRevision;
use App\Models\Server;
use App\Models\User;
use App\Modules\ConfigRevisions\Services\ConfigRevisionContext;
use App\Modules\ConfigRevisions\Services\ConfigRevisionRecorder;

/**
 * ConfigRevision stream for server-level config files edited in the
 * unified Configuration workspace (webserver, PHP, Redis, system, etc.).
 */
final class ServerConfigFileEditor
{
    public const KIND = 'server_config_file';

    public function __construct(
        protected ConfigRevisionRecorder $recorder,
    ) {}

    public function streamKey(Server $server, string $path): string
    {
        return 'server:'.$server->id.':config:'.hash('sha256', $path);
    }

    /**
     * @return array{path: string, content: string, engine?: string}
     */
    /** @return array<string, mixed> */
    public function snapshotFor(string $path, string $content, ?string $engine = null): array
    {
        $snapshot = [
            'path' => $path,
            'content' => $content,
        ];

        if ($engine !== null && $engine !== '') {
            $snapshot['engine'] = $engine;
        }

        return $snapshot;
    }

    public function snapshotChecksumFor(string $path, string $content, ?string $engine = null): string
    {
        return $this->recorder->checksumFor($this->snapshotFor($path, $content, $engine));
    }

    /**
     * Baseline-on-first-save: snapshot the pre-edit buffer once per stream.
     */
    public function ensureBaseline(
        Server $server,
        string $path,
        string $content,
        ?User $user = null,
        ?string $engine = null,
    ): void {
        $streamKey = $this->streamKey($server, $path);

        if (ConfigRevision::query()->forStream($streamKey)->exists()) {
            return;
        }

        $this->recorder->capture(
            $streamKey,
            self::KIND,
            $this->snapshotFor($path, $content, $engine),
            new ConfigRevisionContext(
                server: $server,
                user: $user,
                summary: __('Baseline (auto-captured)'),
            ),
        );
    }

    public function recordWrite(
        Server $server,
        string $path,
        string $content,
        ?User $user = null,
        ?string $summary = null,
        ?string $engine = null,
    ): ?ConfigRevision {
        return $this->recorder->capture(
            $this->streamKey($server, $path),
            self::KIND,
            $this->snapshotFor($path, $content, $engine),
            new ConfigRevisionContext(
                server: $server,
                user: $user,
                summary: $summary ?? __('Saved from configuration editor'),
            ),
        );
    }

    public function lookupRevision(Server $server, string $path, string $revisionId): ?ConfigRevision
    {
        return ConfigRevision::query()
            ->whereKey($revisionId)
            ->where('stream_key', $this->streamKey($server, $path))
            ->first();
    }
}
